<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Http;

echo "Consultando API de Grupos (carele, sede 1, gestion 1-2026)...\n";

$response = Http::timeout(30)->get("http://181.188.185.211:9098/api/Grupos/listar/", [
    'gestion' => '1-2026',
    'carrera' => 'carele',
    'sede' => 1
]);

if ($response->successful()) {
    $data = $response->json();
    echo "Total registros: " . count($data) . "\n";
    foreach ($data as $item) {
        $siglaP = $item['siglaP'] ?? '';
        $docente = $item['docente'] ?? '';
        
        if (strpos($siglaP, 'ELC-213') !== false || strpos($docente, '4534773') !== false) {
            echo "- SiglaP: $siglaP, Materia: {$item['materia']}, Grupo: {$item['grupo']}, Docente: $docente\n";
        }
    }
} else {
    echo "Fallo al consultar la API: " . $response->status() . "\n";
}

echo "\nConsultando API de Grupos (carenl, sede 1, gestion 1-2026)...\n";
$responseEnf = Http::timeout(30)->get("http://181.188.185.211:9098/api/Grupos/listar/", [
    'gestion' => '1-2026',
    'carrera' => 'carenl',
    'sede' => 1
]);

if ($responseEnf->successful()) {
    $dataEnf = $responseEnf->json();
    foreach ($dataEnf as $item) {
        $siglaP = $item['siglaP'] ?? '';
        if (strpos($siglaP, 'ENF-115') !== false || strpos($siglaP, 'ENF') !== false && strpos(strtoupper($item['materia']), 'TECNI') !== false) {
            echo "- SiglaP: $siglaP, Materia: {$item['materia']}, Grupo: {$item['grupo']}, Docente: {$item['docente']}\n";
        }
    }
} else {
    echo "Fallo al consultar la API ENF: " . $response->status() . "\n";
}
