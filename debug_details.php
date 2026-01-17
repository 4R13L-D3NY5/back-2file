<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use App\Models\Asignatura;
use App\Models\Carrera;

$output = "";

$items = Asignatura::where('codigo', 'SIS-325')->get();
$output .= "Found " . $items->count() . " items with code SIS-325:\n\n";

foreach ($items as $item) {
    $carrera = Carrera::find($item->carrera_id);
    $carreraName = $carrera ? $carrera->nombre : 'NULL';
    $sedeName = ($carrera && $carrera->sede) ? $carrera->sede->nombre : 'NULL';
    $sedeId = $carrera ? $carrera->sede_id : 'NULL';

    $output .= "ID: {$item->id} | Name: {$item->nombre} | Carrera: {$carreraName} (ID: {$item->carrera_id}) | Sede: {$sedeName} (ID: {$sedeId})\n";
}

file_put_contents(__DIR__ . '/output_clean.txt', $output);
echo "Done.\n";
