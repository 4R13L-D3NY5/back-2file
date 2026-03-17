<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;

echo "Seguimientos:\n";
print_r(Schema::getColumnListing('seguimientos'));
echo "\nEstudiantes:\n";
print_r(Schema::getColumnListing('estudiantes'));
