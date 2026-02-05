<?php

use Illuminate\Support\Facades\Http;

$url = 'http://181.188.185.211:9098/api/Grupos/listar/';
$carrera = 'CARADM';
$gestion = '1-2026';

echo "Testing API for $carrera in $gestion...\n";

// Test 1: Sede ID (1 for Cochabamba)
echo "\n--- Test 1: sede=1 ---\n";
try {
    $response = Http::get($url, ['gestion' => $gestion, 'sede' => 1, 'carrera' => $carrera]);
    $count = count($response->json() ?? []);
    echo "Status: " . $response->status() . " | Items: $count\n";
    if ($count > 0) echo "First Item: " . json_encode($response->json()[0]) . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Test 2: Sede Code ('cba')
echo "\n--- Test 2: sede='cba' ---\n";
try {
    $response = Http::get($url, ['gestion' => $gestion, 'sede' => 'cba', 'carrera' => $carrera]);
    $count = count($response->json() ?? []);
    echo "Status: " . $response->status() . " | Items: $count\n";
    if ($count > 0) echo "First Item: " . json_encode($response->json()[0]) . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
