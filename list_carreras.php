<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$carreras = \App\Models\Carrera::all();
foreach ($carreras as $c) {
    echo "ID: {$c->id} | Cod: {$c->codigo} | Nom: {$c->nombre}\n";
}
