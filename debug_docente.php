<?php

use App\Models\Docente;
use App\Models\Grupo;
use App\Models\Asignatura;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ci = '5247779';
echo "--- DIAGNÓSTICO DOCENTE $ci ---\n";

$docente = Docente::where('ci', $ci)->with('user')->first();

if (!$docente) {
    echo "ERROR: Docente con CI $ci no encontrado.\n";
    exit;
}

echo "Docente ID: {$docente->id}\n";
echo "Nombre: {$docente->nombre_completo}\n";
echo "User ID: " . ($docente->user_id ?? 'NULL') . "\n";
echo "Username: " . ($docente->user->username ?? 'N/A') . "\n";

echo "\n--- GRUPOS VINCULADOS ---\n";
$grupos = Grupo::where('docente_id', $docente->id)->with('asignatura')->get();

foreach ($grupos as $g) {
    echo "Grupo: {$g->nombre} ({$g->tipo})\n";
    echo "  - Asignatura: {$g->asignatura->codigo} - {$g->asignatura->nombre} (ID: {$g->asignatura_id})\n";
    echo "  - Gestión: {$g->gestion}\n";
    echo "  - Sede ID: {$g->sede_id}\n";
}

echo "\n--- BÚSQUEDA ESPECÍFICA MED-114 ---\n";
$asig = Asignatura::where('codigo', 'MED-114')->get();
foreach ($asig as $a) {
    echo "Asignatura ID: {$a->id}, Nombre: {$a->nombre}, Plan: {$a->plan_estudios}, Borrado: " . ($a->deleted_at ? 'SÍ' : 'NO') . "\n";
}
