<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use App\Models\Asignatura;

$asignaturas = Asignatura::where('codigo', 'SIS-325')
    ->with('carrera.sede')
    ->get();

echo "Found " . $asignaturas->count() . " items with code SIS-325:\n\n";

foreach ($asignaturas as $a) {
    try {
        $carreraName = $a->carrera ? $a->carrera->nombre : 'NULL';
        $sedeName = ($a->carrera && $a->carrera->sede) ? $a->carrera->sede->nombre : 'NULL';

        echo "ID: [{$a->id}] | Code: {$a->codigo} | Name: {$a->nombre}\n";
        echo "   -> Carrera: {$carreraName} | Sede: {$sedeName}\n";
        echo "---------------------------------------------------\n";
    } catch (\Exception $e) {
        echo "Error processing item {$a->id}: " . $e->getMessage() . "\n";
    }
}
