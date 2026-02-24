<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Grupo;
use App\Models\Seguimiento;

echo "--- Buscando Grupos de Taller de Redes ---\n";
$grupos = Grupo::where('nombre', 'LIKE', '%Taller de Redes%')->with('asignatura')->get();
foreach ($grupos as $g) {
    echo "ID: {$g->id} | Nombre: {$g->nombre} | Asignatura: {$g->asignatura->nombre}\n";
    
    $segs = Seguimiento::where('grupo_id', $g->id)->orderBy('fecha')->get();
    echo "Seguimientos encontrados: " . $segs->count() . "\n";
    foreach ($segs as $s) {
        echo "  - ID: {$s->id} | Fecha: {$s->fecha} | Crono_ID: {$s->cronograma_id}\n";
    }
}
