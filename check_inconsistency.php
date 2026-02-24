<?php

use App\Models\Cronograma;
use App\Models\Seguimiento;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- DATA INCONSISTENCY CHECK ---\n";

$orphanedCronos = Cronograma::whereNull('fecha')->orWhereNull('grupo_id')->count();
echo "Cronogramas with NULL fecha or grupo_id: $orphanedCronos\n";

if ($orphanedCronos > 0) {
    echo "Sample of corrupted cronos:\n";
    $samples = Cronograma::whereNull('fecha')->orWhereNull('grupo_id')
        ->with('asignatura')
        ->take(10)->get();
        
    foreach ($samples as $s) {
        $seg = Seguimiento::where('cronograma_id', $s->id)->first();
        echo "ID: " . $s->id . " | Asig: " . ($s->asignatura->nombre ?? 'NULL') . " | SegFound: " . ($seg ? "YES (Fecha: {$seg->fecha}, Grupo: {$seg->grupo_id})" : "NO") . "\n";
    }
}

echo "--- END ---\n";
