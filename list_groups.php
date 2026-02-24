<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Grupo;

$grupos = Grupo::with('asignatura')->get();
foreach ($grupos as $g) {
    echo "ID: {$g->id} | Nombre: {$g->nombre} | Asignatura: " . ($g->asignatura->nombre ?? 'N/A') . "\n";
}
