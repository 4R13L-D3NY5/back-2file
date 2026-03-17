<?php
/**
 * cleanup_scoped.php
 * Script destructivo para simular pérdida de datos.
 * Desliga docentes y borra cronogramas de asignaturas con códigos extendidos.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Simulando Pérdida de Datos (Cleanup Agresivo) ===\n\n";

$scoped = DB::table('asignaturas')
    ->whereRaw("codigo REGEXP '^[A-Z]{2,5}-[0-9]{3}-.+'")
    ->get(['id', 'codigo']);

echo "Asignaturas con códigos largos encontradas: " . $scoped->count() . "\n";

if ($scoped->isEmpty()) {
    echo "Nada que limpiar. Asegúrate de haber importado el backup en la DB 'academico'.\n";
    exit(0);
}

$ids = $scoped->pluck('id')->toArray();

// 1. Desligar docentes
$gruposActualizados = DB::table('grupos')
    ->whereIn('asignatura_id', $ids)
    ->whereNotNull('docente_id')
    ->update(['docente_id' => null]);
echo "Grupos desligados de docente: $gruposActualizados\n";

// 2. Eliminar cronogramas
$cronosEliminados = DB::table('cronogramas')
    ->whereIn('asignatura_id', $ids)
    ->delete();
echo "Cronogramas eliminados (Pérdida de datos): $cronosEliminados\n";

echo "\n=== Simulación de Limpieza Completada ===\n";
