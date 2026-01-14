<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Checking Tables...\n";
$tables = ['carreras', 'sedes'];
foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        echo "Table [$table] exists.\n";
        $columns = Schema::getColumnListing($table);
        echo "Columns: " . implode(', ', $columns) . "\n";
    } else {
        echo "Table [$table] DOES NOT EXIST.\n";
    }
}
