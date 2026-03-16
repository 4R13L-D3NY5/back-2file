<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

echo "=== DIAGNÓSTICO ENF-115-COC-CARENL ===\n";

$idB_target = 2286;
$asigB = DB::table("$dbBackup.asignaturas")->where('id', $idB_target)->first();

if (!$asigB) {
    die("ERROR: No se encontró la asignatura con ID $idB_target en el backup.\n");
}

echo "Asignatura Backup: [{$asigB->id}] {$asigB->codigo} - {$asigB->nombre} (Plan: {$asigB->plan_estudios})\n";

$cronosB = DB::table("$dbBackup.cronogramas")->where('asignatura_id', $asigB->id)->count();
echo "Cronogramas en Backup para esta ID: $cronosB\n";

// Buscar equivalente en Current
$codigoBase = 'ENF-115';
echo "\n[2] MATERIAS ACTUALES QUE COINCIDEN CON ENF-115:\n";
$allAsigsC = DB::table("$dbCurrent.asignaturas")->where('codigo', 'LIKE', 'ENF-115%')->get();

foreach($allAsigsC as $ac) {
    $cronosC = DB::table("$dbCurrent.cronogramas")->where('asignatura_id', $ac->id)->count();
    echo "  - [ID: {$ac->id}] Codigo: {$ac->codigo}, Nombre: {$ac->nombre}, Plan: {$ac->plan_estudios}, Cronos: $cronosC\n";
    
    if ($cronosC > 0) {
        $gruposC = DB::table("$dbCurrent.grupos")->where('asignatura_id', $ac->id)->get();
        foreach($gruposC as $gc) {
            $cC = DB::table("$dbCurrent.cronogramas")->where('grupo_id', $gc->id)->count();
            echo "    -> Grupo '{$gc->nombre}' (ID {$gc->id}): $cC cronogramas\n";
        }
    }
}


// Simular lógica de matching del unificado con relajación de código
echo "\n--- SIMULANDO MATCHING GLOBAL (IGNORANDO CÓDIGO) ---\n";
$planB = $asigB->plan_estudios ?: 'N';

$asigC_by_name = DB::table("$dbCurrent.asignaturas")
    ->where('nombre', 'LIKE', '%TECNICAS%ENFERMERIA%')
    ->get();

foreach($asigC_by_name as $ac) {
    $planC = $ac->plan_estudios ?: 'N';
    similar_text(strtoupper($ac->nombre), strtoupper($asigB->nombre), $percent);
    echo "  - Current [ID: {$ac->id}] Codigo: {$ac->codigo}, Nombre: {$ac->nombre}, Plan: $planC. Similitud con B: $percent%\n";
}

echo "\n--- REVISANDO LÓGICA PASO 3 EN UNIFICADO ---\n";
// Replicar exactamente la lógica del unificado Paso 3
$candidatosB = DB::table("$dbBackup.asignaturas")->where('codigo', 'LIKE', 'ENF-115%')->get();
echo "Para ENF-115 en Current, candidatos en Backup:\n";
foreach($candidatosB as $cand) {
     echo "  - ID: {$cand->id}, Nombre: {$cand->nombre}, Plan: {$cand->plan_estudios}\n";
}

