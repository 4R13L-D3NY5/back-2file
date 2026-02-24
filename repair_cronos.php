<?php

use App\Models\Cronograma;
use App\Models\Seguimiento;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- START DATA REPAIR ---\n";

$affectedCount = 0;

// Find all seguimientos that have a valid cronograma_id
$seguimientos = Seguimiento::whereNotNull('cronograma_id')->get();

foreach ($seguimientos as $seg) {
    $crono = Cronograma::find($seg->cronograma_id);
    
    if ($crono) {
        $needsUpdate = false;
        
        // If crono is missing date or group, take it from seguimiento
        if (empty($crono->fecha) && !empty($seg->fecha)) {
            $crono->fecha = $seg->fecha;
            $needsUpdate = true;
        }
        
        if (empty($crono->grupo_id) && !empty($seg->grupo_id)) {
            $crono->grupo_id = $seg->grupo_id;
            $needsUpdate = true;
        }
        
        if ($needsUpdate) {
            $crono->save();
            $affectedCount++;
            echo "Repaired Crono ID: {$crono->id} | Set Fecha: {$crono->fecha} | Set Grupo: {$crono->grupo_id}\n";
        }
    }
}

echo "\nTotal cronogramas repaired: $affectedCount\n";
echo "--- END DATA REPAIR ---\n";
