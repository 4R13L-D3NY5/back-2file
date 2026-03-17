<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Updating carrera codigos...\n";

// Update Enfermería (ID 7) from 'carsis' to 'carenl'
$affected = DB::table('carreras')
    ->where('id', 7)
    ->update(['codigo' => 'carenl']);
echo "Updated Enfermería (ID 7) codigo to 'carenl': $affected row\n";

// Update Ingeniería de Sistemas (ID 34) from empty to 'carsis'
$affected = DB::table('carreras')
    ->where('id', 34)
    ->update(['codigo' => 'carsis']);
echo "Updated Ingeniería de Sistemas (ID 34) codigo to 'carsis': $affected row\n";

// Verify
$carreras = DB::table('carreras')->whereIn('id', [7, 34])->get();
foreach ($carreras as $c) {
    echo "ID: $c->id, Nombre: $c->nombre, Codigo: $c->codigo\n";
}

echo "Done.\n";