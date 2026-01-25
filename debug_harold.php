<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Docente;
use App\Models\User;

echo "--- CHECKING TEACHER DATA ---\n";
$name = "HAROLD MARCO ANTONIO ROJAS TORRES";
$docente = Docente::where('nombre_completo', 'like', "%HAROLD MARCO%")->with('sede', 'grupos.asignatura.carreras.sedes')->first();

if (!$docente) {
    echo "X Docente not found.\n";
    exit;
}

echo "Docente Found: {$docente->nombre_completo} (ID: {$docente->id})\n";
echo "Sede ID (Direct): " . ($docente->sede_id ?? 'NULL') . "\n";
echo "Sede Relation: " . ($docente->sede ? $docente->sede->nombre : 'NULL') . "\n";

echo "Grupos: " . $docente->grupos->count() . "\n";
foreach ($docente->grupos as $grupo) {
    echo "  - Grupo: {$grupo->nombre} ({$grupo->asignatura->nombre})\n";
    // Check inferred sede
    if ($grupo->asignatura && $grupo->asignatura->carreras->isNotEmpty()) {
        foreach ($grupo->asignatura->carreras as $carrera) {
            echo "    -> Carrera: {$carrera->nombre}\n";
            foreach ($carrera->sedes as $sede) {
                echo "       -> Sede Linked: {$sede->nombre} (ID: {$sede->id})\n";
            }
        }
    }
}
