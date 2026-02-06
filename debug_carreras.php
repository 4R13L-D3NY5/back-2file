<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Carrera;

echo "Listado de Carreras en Base de Datos:\n";
echo "ID | Codigo | Nombre\n";
echo "---|--------|-------\n";

foreach (Carrera::all() as $c) {
    echo "{$c->id} | {$c->codigo} | {$c->nombre}\n";
}
