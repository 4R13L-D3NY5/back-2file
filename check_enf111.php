<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;

$asignaturas = Asignatura::where('codigo', 'ENF-111')->get();
foreach ($asignaturas as $a) {
    echo "ID: $a->id, Codigo: $a->codigo, Nombre: $a->nombre, Plan: $a->plan_estudios, Carreras: ";
    $carreras = $a->carreras;
    foreach ($carreras as $c) {
        echo "$c->nombre ($c->codigo), ";
    }
    echo "\n";
}

// Also check grupos count
$grupos = \App\Models\Grupo::where('asignatura_id', $asignaturas->first()->id)->count();
echo "Total grupos: $grupos\n";