<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;
use App\Models\Grupo;

$codigo = 'MED-226';
$asignatura = Asignatura::where('codigo', $codigo)->first();

echo "Asignatura: {$asignatura->nombre} (ID: {$asignatura->id})\n";

$grupos = Grupo::where('asignatura_id', $asignatura->id)->get();

foreach ($grupos as $g) {
    echo "Grupo: '{$g->nombre}' (ID: {$g->id})\n";
    echo "  - Grupo Tipo: " . (is_null($g->tipo) ? 'NULL' : "'{$g->tipo}'") . "\n";
    
    $isNumeric = is_numeric($g->nombre);
    $esTeorico = $g->tipo 
        ? ($g->tipo === 'Teórica' || $g->tipo === 'T') 
        : $isNumeric;
        
    echo "  - Calculated esTeorico: " . ($esTeorico ? 'TRUE' : 'FALSE') . "\n";
    echo "\n";
}
