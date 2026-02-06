<?php

use Illuminate\Support\Facades\Http;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Config
$sedeId = 1; // Cochabamba
$carreraCode = 'CARSON'; // Ingeniería de Sonido
$gestion = '1-2026'; // Current management

echo "DEBUG: Fetching groups for $carreraCode in Sede $sedeId ($gestion)...\n";

$url = 'http://181.188.185.211:9098/api/Grupos/listar/';
$response = Http::get($url, [
    'gestion' => $gestion,
    'sede' => $sedeId,
    'carrera' => $carreraCode
]);

if ($response->failed()) {
    die("API Error: " . $response->status() . "\n");
}

$data = $response->json();
echo "API Response Items: " . count($data) . "\n";

// Filter for SON-115
$matches = array_filter($data, function ($item) {
    return isset($item['siglaP']) && $item['siglaP'] === 'SON-115';
});

echo "Found " . count($matches) . " groups for SON-115:\n";
foreach ($matches as $group) {
    echo "------------------------------------------------\n";
    echo "Grupo: " . ($group['grupo'] ?? 'N/A') . "\n";
    echo "Docente: " . ($group['docente'] ?? 'SIN DOCENTE') . "\n";
    echo "CI: " . ($group['ci'] ?? 'N/A') . "\n";
    echo "Horario ID: " . ($group['idHorario'] ?? 'N/A') . "\n";
}
