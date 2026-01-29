<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

try {
    $request = \Illuminate\Http\Request::capture();
    $controller = $app->make(\App\Http\Controllers\DocenteController::class);
    $response = $controller->index($request);
    echo "SUCCESS";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
