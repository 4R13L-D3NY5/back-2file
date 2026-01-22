<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Sede;

$sedes = Sede::all();
foreach ($sedes as $sede) {
    echo "ID: {$sede->id} | Codigo: {$sede->codigo} | Nombre: {$sede->nombre}\n";
}
