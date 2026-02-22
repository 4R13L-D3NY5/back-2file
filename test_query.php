<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$asignaturas = \App\Models\Asignatura::where('nombre', 'like', '%REDES%')
    ->orWhere('codigo', 'like', '%SIS-312%')
    ->select('id', 'codigo', 'nombre', 'sesiones_semanales_teoricas', 'sesiones_semanales_practicas')
    ->get()
    ->toArray();

echo json_encode($asignaturas, JSON_PRETTY_PRINT);
