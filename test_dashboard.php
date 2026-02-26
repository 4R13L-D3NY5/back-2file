<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$request = Illuminate\Http\Request::create('/api/admin/dashboard/director', 'GET', [
    'carrera_id' => 3, 
    'sede_id' => 1
]);

$controller = new App\Http\Controllers\DashboardController();
$response = $controller->director($request);

echo $response->getContent();
