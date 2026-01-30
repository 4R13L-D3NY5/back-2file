<?php

use App\Models\Grupo;
use App\Models\Cronograma;
use App\Models\Asignatura;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- DEBUG START ---\n";
$asignatura = Asignatura::where('codigo', 'SIS-334')->first();
if (!$asignatura) {
    die("Asignatura SIS-334 not found.\n");
}
echo "Asignatura ID: " . $asignatura->id . "\n";

$grupo = Grupo::where('asignatura_id', $asignatura->id)->first();
if (!$grupo) {
    echo "No matching group found for asignatura " . $asignatura->id . "\n";
    // Check if any group exists at all
    echo "Total Groups in DB: " . Grupo::count() . "\n";
} else {
    echo "Grupo ID: " . $grupo->id . "\n";
    $cronosCount = Cronograma::where('grupo_id', $grupo->id)->count();
    echo "Cronogramas Count: " . $cronosCount . "\n";
    
    if ($cronosCount > 0) {
        $cronos = Cronograma::where('grupo_id', $grupo->id)->orderBy('fecha')->take(5)->get();
        foreach ($cronos as $c) {
            echo " - Fecha: " . $c->fecha . " | TemaID: " . $c->tema_id . " | AsignaturaID(in crono): " . ($c->asignatura_id ?? 'NULL') . "\n";
        }
    }
}
echo "--- DEBUG END ---\n";
