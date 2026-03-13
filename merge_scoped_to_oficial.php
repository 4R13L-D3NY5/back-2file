<?php
/**
 * merge_scoped_to_oficial.php
 * ─────────────────────────────────────────────────────────────
 * Migra el contenido de asignaturas con código "scoped"
 *   ej: ENF-112-EL -CARENL, DER-113-COC-CARDER
 * hacia la asignatura oficial correspondiente
 *   ej: ENF-112, DER-113  (mismo plan_estudios)
 *
 * Redirige: grupos, unidades, cronogramas, bibliografias.
 * Duplicados en grupos se eliminan (no se pueden duplicar).
 * Al final elimina definitivamente los registros scoped.
 *
 * USO: php merge_scoped_to_oficial.php
 * ─────────────────────────────────────────────────────────────
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;

echo "=== Migración: Scoped → Oficial ===\n\n";

// Buscar todas las asignaturas scoped (incluyendo soft-deleted)
// Patrón scoped: código con sufijo ej. ENF-112-COC-CARENL
$scopedAll = DB::table('asignaturas')
    ->whereRaw("codigo REGEXP '^[A-Z]{2,5}-[0-9]{3}-.+'")
    ->get(['id', 'codigo', 'nombre', 'plan_estudios', 'deleted_at']);

echo "Asignaturas scoped encontradas: " . $scopedAll->count() . "\n\n";

if ($scopedAll->isEmpty()) {
    echo "Nada que migrar.\n";
    exit(0);
}

$stats = [
    'migradas'      => 0,
    'sin_oficial'   => 0,
    'grupos_redir'  => 0,
    'grupos_del'    => 0,
    'unidades'      => 0,
    'cronogramas'   => 0,
    'bibliografias' => 0,
];

foreach ($scopedAll as $scoped) {
    // Extraer código base: ENF-112-EL-CARENL → ENF-112
    if (!preg_match('/^([A-Z]{2,5}-[0-9]{3})-.+/', $scoped->codigo, $m)) {
        echo "  [SKIP] No se puede extraer base de: {$scoped->codigo}\n";
        DB::table('asignaturas')->where('id', $scoped->id)->delete();
        continue;
    }

    $codigoBase = $m[1];
    $plan       = $scoped->plan_estudios ?: 'N';

    // Buscar la asignatura oficial (mismo código base + mismo plan, no eliminada)
    $oficial = DB::table('asignaturas')
        ->where('codigo', $codigoBase)
        ->where('plan_estudios', $plan)
        ->whereNull('deleted_at')
        ->where('estado', '!=', 'cancelado')
        ->first();

    // Fallback: sin filtro de plan
    if (!$oficial) {
        $oficial = DB::table('asignaturas')
            ->where('codigo', $codigoBase)
            ->whereNull('deleted_at')
            ->where('estado', '!=', 'cancelado')
            ->first();
    }

    if (!$oficial) {
        echo "  [SIN OFICIAL] {$scoped->codigo} → eliminando sin migrar\n";
        DB::table('grupos')->where('asignatura_id', $scoped->id)->delete();
        DB::table('asignaturas')->where('id', $scoped->id)->delete();
        $stats['sin_oficial']++;
        continue;
    }

    echo "  MERGE [{$scoped->id}] {$scoped->codigo} → [{$oficial->id}] {$oficial->codigo}\n";

    // ─── Grupos: fila por fila con try/catch ───
    $gruposScoped = DB::table('grupos')->where('asignatura_id', $scoped->id)->get(['id']);
    foreach ($gruposScoped as $g) {
        try {
            DB::table('grupos')->where('id', $g->id)->update(['asignatura_id' => $oficial->id]);
            $stats['grupos_redir']++;
        } catch (UniqueConstraintViolationException $e) {
            // Duplicado — el grupo ya existe en la oficial, eliminar el scoped
            DB::table('grupos')->where('id', $g->id)->delete();
            $stats['grupos_del']++;
        }
    }

    // ─── Unidades ───
    $stats['unidades'] += DB::table('unidades')
        ->where('asignatura_id', $scoped->id)
        ->update(['asignatura_id' => $oficial->id]);

    // ─── Cronogramas ───
    $stats['cronogramas'] += DB::table('cronogramas')
        ->where('asignatura_id', $scoped->id)
        ->update(['asignatura_id' => $oficial->id]);

    // ─── Bibliografias ───
    $stats['bibliografias'] += DB::table('bibliografias')
        ->where('asignatura_id', $scoped->id)
        ->update(['asignatura_id' => $oficial->id]);

    // ─── Limpiar pivot y eliminar scoped definitivamente ───
    DB::table('asignatura_carrera')->where('asignatura_id', $scoped->id)->delete();
    DB::table('asignaturas')->where('id', $scoped->id)->delete();

    $stats['migradas']++;
}

echo "\n=== Resumen ===\n";
echo str_pad('Asignaturas migradas:', 26)      . $stats['migradas']      . "\n";
echo str_pad('Sin oficial (eliminadas):', 26)  . $stats['sin_oficial']   . "\n";
echo str_pad('Grupos redirigidos:', 26)        . $stats['grupos_redir']  . "\n";
echo str_pad('Grupos duplicados (borr.):', 26) . $stats['grupos_del']    . "\n";
echo str_pad('Unidades redirigidas:', 26)      . $stats['unidades']      . "\n";
echo str_pad('Cronogramas redirigidos:', 26)   . $stats['cronogramas']   . "\n";
echo str_pad('Bibliografias redirigidas:', 26) . $stats['bibliografias'] . "\n";
echo "\n=== Completado ===\n";
