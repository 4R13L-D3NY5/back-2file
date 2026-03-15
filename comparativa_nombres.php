<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';
$codes = ['ELC-213', 'ENF-115'];

foreach ($codes as $code) {
    echo "=== COMPARA SUBJECT: $code ===\n";
    
    // BACKUP
    $asigB = DB::table("$dbBackup.asignaturas")->where('codigo', $code)->first();
    if ($asigB) {
        echo "[BACKUP] Nombre: {$asigB->nombre}, Plan: " . ($asigB->plan_estudios ?: 'N') . "\n";
    } else {
        echo "[BACKUP] NO ENCONTRADO\n";
    }

    // CURRENT
    $asigC = DB::table("$dbCurrent.asignaturas")->where('codigo', $code)->whereNull('deleted_at')->get();
    foreach ($asigC as $ac) {
        echo "[CURRENT] ID: {$ac->id}, Nombre: {$ac->nombre}, Plan: " . ($ac->plan_estudios ?: 'N') . "\n";
    }
    echo "\n";
}

// Buscar por nombre en CURRENT
echo "=== BUSCANDO POR NOMBRE EN CURRENT ===\n";
$names = ['PROBABILIDAD', 'TECNICAS DE ENFERMERIA'];
foreach ($names as $name) {
    $res = DB::table("$dbCurrent.asignaturas")->where('nombre', 'LIKE', "%$name%")->whereNull('deleted_at')->get();
    foreach ($res as $r) {
        echo "Match: ID: {$r->id}, Codigo: {$r->codigo}, Nombre: {$r->nombre}, Plan: {$r->plan_estudios}\n";
    }
}
