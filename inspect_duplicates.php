<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\RolExamen;

$examenes = RolExamen::where('materia_codigo', 'like', '%SIS-325%')->get();

foreach ($examenes as $e) {
    echo "ID: {$e->id}, Cód: [{$e->materia_codigo}], Grupo: [" . var_export($e->grupo, true) . "], Tipo: [{$e->tipo_examen}], Fecha: [{$e->fecha}], Gestión: [{$e->gestion}], Carrera: [{$e->carrera_id}]\n";
}
echo "\nTotal rows: " . $examenes->count() . "\n";
