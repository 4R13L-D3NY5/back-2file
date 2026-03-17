<?php
/**
 * merge_scoped_to_oficial.php (v5 - Ultra Robusto)
 * ─────────────────────────────────────────────────────────────
 * Migra contenido de códigos "scoped" (ej: ENF-112-COC-CARENL)
 * hacia sus versiones "oficiales" (ej: ENF-112).
 * 
 * MEJORA v5: 
 * - Si hay colisión de grupos (ya existe el oficial), TRANSFIERE la data
 *   (docente, cronogramas, horarios, seguimientos) del scoped al oficial
 *   antes de borrar el duplicado.
 * ─────────────────────────────────────────────────────────────
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Iniciando Unificación de Asignaturas (v5 - Transferencia de Grupos) ===\n";

$scopedAsigs = DB::table('asignaturas')
    ->where(function($q) {
        $q->where('codigo', 'LIKE', '%-%-%')
          ->orWhere('codigo', 'REGEXP', '-[A-Z][A-Z]+$');
    })
    ->whereNull('deleted_at')
    ->get();

echo "Asignaturas 'scoped' encontradas: " . $scopedAsigs->count() . "\n";

$stats = ['migradas' => 0, 'errores' => 0];

foreach ($scopedAsigs as $scoped) {
    if (!preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $scoped->codigo, $matches)) {
        continue;
    }

    $codigoBase = $matches[1];
    $plan = $scoped->plan_estudios ?: 'N';

    $candidatos = DB::table('asignaturas')
        ->where('codigo', $codigoBase)
        ->where('id', '!=', $scoped->id)
        ->whereNull('deleted_at')
        ->where('estado', '!=', 'cancelado')
        ->get();

    $oficial = null;

    if ($candidatos->count() == 1) {
        $oficial = $candidatos->first();
    } elseif ($candidatos->count() > 1) {
        $bestScore = -1;
        foreach ($candidatos as $cand) {
            similar_text(strtoupper($scoped->nombre), strtoupper($cand->nombre), $percent);
            if (($cand->plan_estudios ?: 'N') == $plan) { $percent += 10; }
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $oficial = $cand;
            }
        }
    }

    if (!$oficial) {
        echo "[SIN OFICIAL] {$scoped->codigo} ({$scoped->nombre})\n";
        continue;
    }

    echo "Migrando: {$scoped->codigo} -> {$oficial->codigo} (IDs: {$scoped->id} -> {$oficial->id})\n";

    DB::beginTransaction();
    try {
        DB::table('asignatura_carrera')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficial->id]);
        DB::table('unidades')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficial->id]);
        DB::table('bibliografias')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficial->id]);

        $gruposScoped = DB::table('grupos')->where('asignatura_id', $scoped->id)->get();
        foreach ($gruposScoped as $gs) {
            $grupoOficial = DB::table('grupos')
                ->where('asignatura_id', $oficial->id)
                ->where('nombre',     $gs->nombre)
                ->where('gestion',    $gs->gestion)
                ->where('sede_id',    $gs->sede_id)
                ->first();

            if ($grupoOficial) {
                echo "  - Colisión en grupo {$gs->nombre}: Transfiriendo data a Oficial ID {$grupoOficial->id}...\n";
                
                // 1. Transferir Docente si el oficial está vacío
                if (empty($grupoOficial->docente_id) && !empty($gs->docente_id)) {
                    DB::table('grupos')->where('id', $grupoOficial->id)->update(['docente_id' => $gs->docente_id]);
                }
                
                // 2. Transferir Carrera si el oficial está vacío
                if (empty($grupoOficial->carrera_id) && !empty($gs->carrera_id)) {
                    DB::table('grupos')->where('id', $grupoOficial->id)->update(['carrera_id' => $gs->carrera_id]);
                }

                // 3. Mover Seguimientos, Horarios, Cronogramas (si no existen en oficial)
                DB::table('seguimientos')->where('grupo_id', $gs->id)->update(['grupo_id' => $grupoOficial->id]);
                DB::table('horarios')->where('grupo_id', $gs->id)->update(['grupo_id' => $grupoOficial->id]);
                DB::table('cronogramas')->where('grupo_id', $gs->id)->update(['grupo_id' => $grupoOficial->id]);

                // Borrar el grupo scoped que ya quedó vacío
                DB::table('grupos')->where('id', $gs->id)->delete();
            } else {
                DB::table('grupos')->where('id', $gs->id)->update(['asignatura_id' => $oficial->id]);
            }
        }

        DB::table('asignaturas')->where('id', $scoped->id)->delete();
        DB::commit();
        $stats['migradas']++;
    } catch (\Exception $e) {
        DB::rollBack();
        echo "  [ERROR] " . $e->getMessage() . "\n";
        $stats['errores']++;
    }
}

echo "\n=== Resumen ===\n";
echo "Migradas: " . $stats['migradas'] . "\n";
echo "Errores:  " . $stats['errores'] . "\n";
echo "=== Fin ===\n";
