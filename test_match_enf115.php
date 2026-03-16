<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

$asigC = DB::table('asignaturas')->where('codigo', 'ENF-115')->whereNull('deleted_at')->first();

if (!$asigC) {
    die("Asignatura ENF-115 no encontrada en Current.\n");
}

echo "Buscando para: [{$asigC->id}] {$asigC->codigo} - {$asigC->nombre}\n";

$rootCode = $asigC->codigo;
if (preg_match('/^([A-Z]+-[0-9]+)/', $asigC->codigo, $m)) {
    $rootCode = $m[1];
}
echo "Root Code: $rootCode\n";

$candidatosB = DB::table("$dbBackup.asignaturas")->where('codigo', 'LIKE', $rootCode . '%')->get();
echo "Candidatos por Código en Backup: " . $candidatosB->count() . "\n";
foreach ($candidatosB as $cand) {
    echo "  - [{$cand->id}] {$cand->codigo} | {$cand->nombre} | Plan: {$cand->plan_estudios}\n";
}

$planC = $asigC->plan_estudios ?: 'N';
$matchesB = [];
foreach ($candidatosB as $cand) {
    $planB = $cand->plan_estudios ?: 'N';
    similar_text(strtoupper($asigC->nombre), strtoupper($cand->nombre), $percent);
    echo "    -> Similitud con {$cand->codigo}: $percent%. Plan: $planB vs $planC\n";
    
    if (($planB === $planC || $percent > 95) && $percent > 70) {
        $matchesB[] = $cand;
        echo "      [MATCH FOUND]\n";
    }
}

if (empty($matchesB)) {
    echo "No hay matches por código. Probando fallback por nombre...\n";
    $candidatosB = DB::table("$dbBackup.asignaturas")
        ->where('nombre', 'LIKE', '%' . substr($asigC->nombre, 0, 10) . '%')
        ->get();
    echo "Candidatos por Nombre en Backup: " . $candidatosB->count() . "\n";
}

foreach ($matchesB as $asigB) {
    $cronosB = DB::table("$dbBackup.cronogramas")->where('asignatura_id', $asigB->id)->count();
    echo "Cronogramas en Backup para {$asigB->codigo}: $cronosB\n";
}
