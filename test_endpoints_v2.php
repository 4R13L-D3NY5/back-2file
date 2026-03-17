<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

echo "=== Testing management endpoints ===\n";

// 1. Login to get token
$loginUrl = 'http://127.0.0.1:8000/api/login';
$credentials = [
    'username' => 'admin@unitepc.edu.bo',
    'password' => 'password',
];

echo "Logging in...\n";
$response = Http::withHeaders(['Accept' => 'application/json'])->post($loginUrl, $credentials);
if ($response->failed()) {
    echo "Login failed: " . $response->status() . "\n";
    echo $response->body() . "\n";
    exit(1);
}

$loginData = $response->json();
echo "Login response keys: " . implode(', ', array_keys($loginData)) . "\n";
$token = $loginData['token'] ?? null;
if (!$token) {
    echo "No token in response\n";
    var_dump($loginData);
    exit(1);
}

echo "Token obtained: " . substr($token, 0, 20) . "...\n";

// 2. Fetch a grupo with docente (plan N) for testing quitar
$grupoConDocente = DB::table('grupos')
    ->join('asignaturas', 'asignaturas.id', '=', 'grupos.asignatura_id')
    ->where('asignaturas.codigo', 'ENF-111')
    ->where('asignaturas.plan_estudios', 'N')
    ->whereNotNull('grupos.docente_id')
    ->select('grupos.id as grupo_id', 'grupos.docente_id')
    ->first();

if (!$grupoConDocente) {
    echo "No grupo with docente found for ENF-111 plan N. Trying plan A.\n";
    $grupoConDocente = DB::table('grupos')
        ->join('asignaturas', 'asignaturas.id', '=', 'grupos.asignatura_id')
        ->where('asignaturas.codigo', 'ENF-111')
        ->where('asignaturas.plan_estudios', 'A')
        ->whereNotNull('grupos.docente_id')
        ->select('grupos.id as grupo_id', 'grupos.docente_id')
        ->first();
}

if (!$grupoConDocente) {
    echo "No grupo with docente found at all. Cannot test quitar.\n";
    exit(1);
}

echo "Found grupo with docente: grupo_id={$grupoConDocente->grupo_id}, docente_id={$grupoConDocente->docente_id}\n";

// Test quitar-grupo-docente
$quitarUrl = 'http://127.0.0.1:8000/api/grupos-externo/quitar-grupo-docente';
$quitarData = ['grupo_id' => $grupoConDocente->grupo_id];

echo "Calling quitar-grupo-docente...\n";
$response = Http::withToken($token)->withHeaders(['Accept' => 'application/json'])->post($quitarUrl, $quitarData);
echo "Status: " . $response->status() . "\n";
echo "Response: " . $response->body() . "\n";

// Wait a moment
sleep(1);

// Now test asignar-grupo-docente with same grupo (docente removed)
$asignarUrl = 'http://127.0.0.1:8000/api/grupos-externo/asignar-grupo-docente';
$asignarData = [
    'grupo_id' => $grupoConDocente->grupo_id,
    'docente_id' => $grupoConDocente->docente_id, // re-assign same docente
];

echo "Calling asignar-grupo-docente...\n";
$response = Http::withToken($token)->withHeaders(['Accept' => 'application/json'])->post($asignarUrl, $asignarData);
echo "Status: " . $response->status() . "\n";
echo "Response: " . $response->body() . "\n";

echo "=== Test completed ===\n";