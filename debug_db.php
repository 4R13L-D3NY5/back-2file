<?php

use App\Models\BancoPregunta;
use App\Models\Asignatura;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Debugging
echo "--- DB DEBUG ---\n";
$total = BancoPregunta::count();
echo "Total preguntas en BD: $total\n";

if ($total > 0) {
    $last = BancoPregunta::latest()->first();
    echo "Ultima pregunta ID: {$last->id}, Asignatura: " . ($last->asignatura_id ?? 'NULL') . "\n";
    echo "Enunciado: " . substr($last->enunciado, 0, 50) . "...\n";
    
    // Listar conteo por asignatura_id
    echo "\nConteo por asignatura_id:\n";
    $counts = BancoPregunta::select('asignatura_id', \DB::raw('count(*) as total'))
        ->groupBy('asignatura_id')
        ->get();
    foreach ($counts as $c) {
        echo "Asignatura ID: " . ($c->asignatura_id ?? 'NULL') . " -> Total: {$c->total}\n";
    }
} else {
    echo "La tabla banco_preguntas está vacía.\n";
}
echo "----------------\n";
