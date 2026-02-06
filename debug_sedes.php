<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Sede;

$sedes = Sede::all(['id', 'nombre']);
echo "Listado de Sedes:\n";
foreach ($sedes as $sede) {
    echo "ID: {$sede->id} | Nombre: {$sede->nombre}\n";
}
