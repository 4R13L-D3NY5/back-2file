<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;

$codigo = 'MED-226';
$asignatura = Asignatura::where('codigo', $codigo)->first();

echo "Asignatura: {$asignatura->nombre}\n";

if ($asignatura->horarios_data) {
    foreach ($asignatura->horarios_data as $h) {
        echo "Grupo: " . json_encode($h['grupo']) . "\n";
        echo "  - ID: " . ($h['id'] ?? 'N/A') . "\n";
        echo "  - Tipo (explicit): " . ($h['tipo'] ?? 'NULL') . "\n";
        
        // Emulate JS Logic
        $grupoStr = trim((string)$h['grupo']);
        $isNumeric = is_numeric($grupoStr);
        echo "  - Is Numeric? " . ($isNumeric ? 'YES' : 'NO') . "\n";
        
        $calculatedType = ($h['tipo'] ?? null) 
            ? (($h['tipo'] === 'Teórica' || $h['tipo'] === 'T') ? 'Theoretical' : 'Practical')
            : ($isNumeric ? 'Theoretical' : 'Practical');
            
        echo "  - Calculated Type: $calculatedType\n\n";
    }
} else {
    echo "No horarios_data found.\n";
}
