<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

echo "=== DIAGNÓSTICO ELC-213 (Tania) ===\n";

$asigC = DB::table("$dbCurrent.asignaturas")->where('codigo', 'ELC-213')->where('nombre', 'PROBABILIDAD Y ESTADISTICA')->first();
if (!$asigC) die("ERROR: Asignatura actual no encontrada.\n");

$allAsigsB = DB::table("$dbBackup.asignaturas")->where('codigo', 'LIKE', 'ELC-213%')->where('nombre', 'PROBABILIDAD Y ESTADISTICA')->get();

echo "\n[1] MATERIAS EN BACKUP:\n";
foreach($allAsigsB as $aB) {
    $cronosB = DB::table("$dbBackup.cronogramas")->where('asignatura_id', $aB->id)->count();
    $unidadesB = DB::table("$dbBackup.unidades")->where('asignatura_id', $aB->id)->count();
    $planesB = DB::table("$dbBackup.planificaciones_personales")
        ->join("$dbBackup.temas", 'planificaciones_personales.tema_id', '=', 'temas.id')
        ->join("$dbBackup.unidades", 'temas.unidad_id', '=', 'unidades.id')
        ->where('unidades.asignatura_id', $aB->id)
        ->count();
    
    echo "  - ID {$aB->id} (Codigo: {$aB->codigo}, Plan: {$aB->plan_estudios}): Cronos: $cronosB, Unidades: $unidadesB, Plannings: $planesB\n";
    
    if ($cronosB > 0) {
        $distinctGids = DB::table("$dbBackup.cronogramas")->where('asignatura_id', $aB->id)->distinct()->pluck('grupo_id');
        echo "    Grupos con Cronos en Backup:\n";
        foreach($distinctGids as $gid) {
            $gb = DB::table("$dbBackup.grupos")->where('id', $gid)->first();
            $cC = DB::table("$dbBackup.cronogramas")->where('grupo_id', $gid)->count();
            $nombreG = $gb ? $gb->nombre : "???";
            echo "      - Grupo ID $gid (Nombre: '$nombreG'): $cC cronogramas\n";
        }
    }
}

echo "\n[2] MATERIA ACTUAL (ID {$asigC->id}):\n";
$cronosC = DB::table("$dbCurrent.cronogramas")->where('asignatura_id', $asigC->id)->count();
$unidadesC = DB::table("$dbCurrent.unidades")->where('asignatura_id', $asigC->id)->count();
$planesC = DB::table("$dbCurrent.planificaciones_personales")
    ->join('temas', 'planificaciones_personales.tema_id', '=', 'temas.id')
    ->join('unidades', 'temas.unidad_id', '=', 'unidades.id')
    ->where('unidades.asignatura_id', $asigC->id)
    ->count();
echo "  - Actual: Cronos: $cronosC, Unidades: $unidadesC, Plannings: $planesC\n";

$gruposC = DB::table("$dbCurrent.grupos")->where('asignatura_id', $asigC->id)->get();
foreach($gruposC as $gc) {
    $cC = DB::table("$dbCurrent.cronogramas")->where('grupo_id', $gc->id)->count();
    echo "    - Grupo '{$gc->nombre}' (Sede {$gc->sede_id}): $cC cronogramas\n";
}
