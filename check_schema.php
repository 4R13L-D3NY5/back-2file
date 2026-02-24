<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- DATABASE SCHEMA CHECK ---\n";

$table = 'cronogramas';
$columns = DB::select("SHOW COLUMNS FROM $table");

foreach ($columns as $column) {
    echo "Field: " . $column->Field . " | Type: " . $column->Type . "\n";
}

echo "\n--- RAW DATA SAMPLE (85880, 85879) ---\n";
$data = DB::table($table)->whereIn('id', [85880, 85879])->get();
foreach ($data as $row) {
    echo json_encode($row) . "\n";
}

echo "--- END ---\n";
