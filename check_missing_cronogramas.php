<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

$cronoB = DB::table("$dbBackup.cronogramas")->count();
$cronoC = DB::table("$dbCurrent.cronogramas")->count();

echo "Total Cronogramas en Backup: $cronoB\n";
echo "Total Cronogramas en Current: $cronoC\n";

// Get recreated asignaturas in last hour
$recreatedAsigs = DB::table("$dbCurrent.asignaturas")
    ->where('created_at', '>=', now()->subHours(2))
    ->get();

echo "\nRevisando " . count($recreatedAsigs) . " asignaturas recreadas recientamente:\n";

$faltan = 0;
foreach ($recreatedAsigs as $asigC) {
    // Find matching in backup
    $asigB = DB::table("$dbBackup.asignaturas")
        ->where('nombre', $asigC->nombre)
        ->where('plan_estudios', $asigC->plan_estudios)
        ->first();
        
    if ($asigB) {
        $cB = DB::table("$dbBackup.cronogramas")->where('asignatura_id', $asigB->id)->count();
        $cC = DB::table("$dbCurrent.cronogramas")->where('asignatura_id', $asigC->id)->count();
        
        if ($cB > 0 && $cC == 0) {
            echo " - {$asigC->codigo} {$asigC->nombre}: Backup tiene $cB cronogramas, Current tiene 0\n";
            $faltan++;
        }
    }
}

echo "Total asignaturas con cronogramas faltantes: $faltan\n";
