<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;

$asignatura = Asignatura::with('carreras')->where('codigo', 'ENF-111')->first();

if ($asignatura) {
    echo "Asignatura: " . $asignatura->codigo . " - " . $asignatura->nombre . "\n";
    echo "Plan estudios: " . $asignatura->plan_estudios . "\n";
    echo "Carreras:\n";
    foreach ($asignatura->carreras as $carrera) {
        echo "  - ID: " . $carrera->id . ", Nombre: " . $carrera->nombre . ", Codigo: " . $carrera->codigo . "\n";
    }
} else {
    echo "Asignatura ENF-111 not found.\n";
}

// Also check grupos and docentes
$grupos = \App\Models\Grupo::where('asignatura_id', $asignatura->id)->with('docente')->get();
echo "\nGrupos count: " . $grupos->count() . "\n";
foreach ($grupos as $grupo) {
    echo "Grupo: " . $grupo->nombre . ", Docente: " . ($grupo->docente ? $grupo->docente->nombre_completo : 'Ninguno') . "\n";
}

// Check carrera mapping in carreras table
$carreras = \App\Models\Carrera::all();
echo "\nAll carreras:\n";
foreach ($carreras as $c) {
    echo "ID: " . $c->id . ", Codigo: " . $c->codigo . ", Nombre: " . $c->nombre . "\n";
}