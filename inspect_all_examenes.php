<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\RolExamen;

$examenes = RolExamen::orderBy('id')->get();

echo "ID | Cód | Tipo | Grupo | Fecha | Carrera | Gestión\n";
echo str_repeat('-', 60) . "\n";
foreach ($examenes as $e) {
    echo "{$e->id} | {$e->materia_codigo} | {$e->tipo_examen} | {$e->grupo} | {$e->fecha} | {$e->carrera_id} | {$e->gestion}\n";
}
echo "\nTotal rows: " . $examenes->count() . "\n";
