<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$indexes = DB::select('SHOW INDEX FROM users');
foreach ($indexes as $idx) {
    echo "Columna: {$idx->Column_name} | Unique: " . (!$idx->Non_unique ? 'SI' : 'NO') . " | Index Name: {$idx->Key_name}\n";
}
