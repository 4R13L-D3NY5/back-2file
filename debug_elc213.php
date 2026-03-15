<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

$asigB = DB::table("$dbBackup.asignaturas")->where('codigo', 'LIKE', 'ELC-213%')->where('nombre', 'PROBABILIDAD Y ESTADISTICA')->first();
$asigC = DB::table("$dbCurrent.asignaturas")->where('codigo', 'ELC-213')->where('nombre', 'PROBABILIDAD Y ESTADISTICA')->first();

echo "\n--- TEST SUBJECT DUPLICATES IN BACKUP ---\n";
$allAsigsB = DB::table("$dbBackup.asignaturas")->where('codigo', 'LIKE', 'ELC-213%')->where('nombre', 'PROBABILIDAD Y ESTADISTICA')->get();
foreach($allAsigsB as $aB) {
    $cC = DB::table("$dbBackup.cronogramas")->where('asignatura_id', $aB->id)->count();
    $uC = DB::table("$dbBackup.unidades")->where('asignatura_id', $aB->id)->count();
    echo "  - [ID: {$aB->id}] Codigo: {$aB->codigo}, Plan: {$aB->plan_estudios}, Cronos: $cC, Unidades: $uC\n";
}

echo "\n--- TEST CRONOGRAMAS ---\n";
$gruposCurrent = DB::table("$dbCurrent.grupos")->where('asignatura_id', $asigC->id)->get();
foreach($allAsigsB as $aB) {
    echo "\n  Checking Asig Backup ID: {$aB->id}\n";
    $distinctGroupsB = DB::table("$dbBackup.cronogramas")->where('asignatura_id', $aB->id)->distinct()->pluck('grupo_id');
    foreach($distinctGroupsB as $gidB) {
        $gb = DB::table("$dbBackup.grupos")->where('id', $gidB)->first();
        $countB = DB::table("$dbBackup.cronogramas")->where('grupo_id', $gidB)->count();
        if ($gb) {
            echo "    - [Backup ID: $gidB] Nombre: '{$gb->nombre}', Sede: {$gb->sede_id}, Count: $countB\n";
        } else {
             echo "    - [Backup ID: $gidB] GRUPO NO ENCONTRADO, Count: $countB\n";
        }
echo "\n  Grupos C:\n";
foreach ($gruposCurrent as $gc) {
    $countC = DB::table("$dbCurrent.cronogramas")->where('grupo_id', $gc->id)->count();
    echo "    - Current Grupo '{$gc->nombre}': $countC records.\n";
}


echo "\n--- TEST PLANIFICACIONES ---\n";
$unidadesB = DB::table("$dbBackup.unidades")->where('asignatura_id', $asigB->id)->get();
foreach ($unidadesB as $ub) {
    $temasB = DB::table("$dbBackup.temas")->where('unidad_id', $ub->id)->get();
    foreach ($temasB as $tb) {
        $countPB = DB::table("$dbBackup.planificaciones_personales")->where('tema_id', $tb->id)->count();
        if ($countPB > 0) {
            echo "  Backup Tema '{$tb->titulo}': $countPB planificaciones.\n";
        }
    }
}



