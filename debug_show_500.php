<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\AsignaturaController;
use Illuminate\Http\Request;
// Mock UniversityService and SyncService
use App\Services\University\UniversityService;
use App\Services\AsignaturaSyncService;
use App\Models\Asignatura;

$request = Request::create('/api/asignaturas/819', 'GET');
// Use the ID from the user screenshot (620 or 621 or 851)
// Let's pick 620
$id = 620;

echo "--- DEBUG ACADEMIC SHOW ($id) ---\n";

try {
    // Instantiate Controller manually (ignoring dependency injection complexity for a quick check if simple)
    // Actually, getting it from container is safer
    $controller = app(AsignaturaController::class);

    $response = $controller->show($request, $id);

    echo "Response Status: " . $response->status() . "\n";
    if ($response->status() == 200) {
        echo "Success!\n";
    } else {
        echo "Error Content: " . json_encode($response->getData()) . "\n";
    }
} catch (\Exception $e) {
    echo "EXCEPTION CAUGHT:\n";
    echo $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Stack Trace (Top 5):\n";
    // echo $e->getTraceAsString();
}
