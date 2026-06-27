<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$service = $app->make(App\Services\University\UniversityService::class);
echo json_encode($service->getCourses('cba', 'CARSIS'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
