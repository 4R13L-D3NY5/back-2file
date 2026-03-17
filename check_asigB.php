<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$asigB = DB::table("academico_backup.asignaturas")->first();
echo "Property exists justificacion? " . (property_exists($asigB, 'justificacion') ? 'yes' : 'no') . "\n";
echo "Property exists objetivo_general? " . (property_exists($asigB, 'objetivo_general') ? 'yes' : 'no') . "\n";

print_r($asigB);
