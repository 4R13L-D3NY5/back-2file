<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$asig = DB::table('asignaturas')->where('id', 2576)->first();
if ($asig) {
    echo "ID: {$asig->id}\n";
    echo "Codigo: {$asig->codigo}\n";
    echo "Nombre: {$asig->nombre}\n";
    echo "Justificacion: " . (empty($asig->justificacion) ? "VACIO" : "TIENE CONTENIDO (" . strlen($asig->justificacion) . " bytes)") . "\n";
    echo "Proposito: " . (empty($asig->proposito_general) ? "VACIO" : "TIENE CONTENIDO (" . strlen($asig->proposito_general) . " bytes)") . "\n";
} else {
    echo "Asignatura 2576 no encontrada.\n";
}
