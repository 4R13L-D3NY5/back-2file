<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$data = [
    'nombre' => 'TEST',
    'apellido' => 'USER',
    'email' => 'testuser' . time() . '@example.com',
    'ci' => '12345678',
    'rol_id' => 5, // DIRECTOR_CARRERA
    'carrera' => '1, 3',
    'sede_id' => 1,
    'estado' => 'activo'
];

$request = new Request($data);
$controller = new UserController();

try {
    echo "Iniciando test de store...\n";
    $response = $controller->store($request);
    echo "Respuesta status: " . $response->getStatusCode() . "\n";
    echo "Cuerpo: " . $response->getContent() . "\n";
} catch (\Exception $e) {
    echo "ERROR CATASTROFICO: " . $e->getMessage() . "\n";
    echo "TRAZA: " . $e->getTraceAsString() . "\n";
}
