<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Grupo;
use App\Models\Asignatura;

$asignaturas = Asignatura::where('nombre', 'LIKE', '%REDES%')->get();
foreach ($asignaturas as $a) {
    echo "Asignatura: {$a->id} | {$a->nombre}\n";
    $grupos = Grupo::where('asignatura_id', $a->id)->get();
    foreach ($grupos as $g) {
        echo "  - Grupo: {$g->id} | {$g->nombre}\n";
    }
}
