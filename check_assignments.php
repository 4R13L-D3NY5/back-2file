<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$results = DB::table('campus_carrera')
    ->join('campus', 'campus_carrera.campus_id', '=', 'campus.id')
    ->join('carreras', 'campus_carrera.carrera_id', '=', 'carreras.id')
    ->select('campus.nombre as campus', 'carreras.nombre as carrera', 'campus.id as campus_id', 'carreras.id as carrera_id')
    ->get();

foreach ($results as $row) {
    echo "Campus [{$row->campus_id}] {$row->campus} -> Carrera [{$row->carrera_id}] {$row->carrera}\n";
}

echo "\nTotal asignaciones: " . count($results) . "\n";
