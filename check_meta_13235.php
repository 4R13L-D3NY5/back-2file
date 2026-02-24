<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Grupo;

$g = Grupo::with(['asignatura', 'carrera', 'sede'])->find(13235);
echo "Grupo: {$g->id} | {$g->nombre}\n";
echo "Sede ID: {$g->sede_id} | Nombre: " . ($g->sede->nombre ?? 'N/A') . "\n";
echo "Carrera ID: {$g->carrera_id} | Nombre: " . ($g->carrera->nombre ?? 'N/A') . "\n";
echo "Asignatura: " . ($g->asignatura->nombre ?? 'N/A') . "\n";
