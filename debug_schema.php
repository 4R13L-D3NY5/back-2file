<?php
// Place in back-2file/debug_schema.php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo "Checking Schema...\n";
$tables = ['bloques', 'aulas', 'grupos', 'horarios', 'carrera_sede'];
foreach ($tables as $t) {
    echo "Table '$t' exists? " . (Schema::hasTable($t) ? 'YES' : 'NO') . "\n";
}

echo "\nChecking Columns 'carreras':\n";
$cols = Schema::getColumnListing('carreras');
print_r($cols);

echo "\nChecking Columns 'asignaturas':\n";
$cols = Schema::getColumnListing('asignaturas');
print_r($cols);
