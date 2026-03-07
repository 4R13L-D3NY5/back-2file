<?php
use App\Models\Docente;
use App\Models\Grupo;
use App\Models\Asignatura;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ci = '5247779';
echo "--- DIAGNÓSTICO DOCENTE $ci EN SERVIDOR ---\n";

$docente = Docente::where('ci', $ci)->with('user')->first();
if (!$docente) {
    echo "ERROR: Docente con CI $ci no encontrado.\n";
    // Buscar por ID si el CI falla por alguna razón
    $user = \App\Models\User::where('username', $ci)->first();
    if($user) {
        $docente = Docente::where('user_id', $user->id)->first();
        if($docente) echo "INFO: Encontrado por user_id. Docente ID: {$docente->id}, CI actual en DB: '{$docente->ci}'\n";
    }
}

if ($docente) {
    echo "Docente ID: {$docente->id}\n";
    echo "User ID: " . ($docente->user_id ?? 'NULL') . "\n";
    echo "Sede ID Docente: {$docente->sede_id}\n";

    echo "\n--- GRUPOS VINCULADOS ---\n";
    $grupos = Grupo::where('docente_id', $docente->id)->with('asignatura')->get();
    if($grupos->isEmpty()) echo "No tiene grupos vinculados.\n";
    foreach ($grupos as $g) {
        echo "Grupo: {$g->nombre} ({$g->tipo}), Asignatura: {$g->asignatura->codigo}, Gestión: {$g->gestion}, Sede: {$g->sede_id}\n";
    }
}

echo "\n--- ESTADO DE MED-114 ---\n";
$asigs = Asignatura::where('codigo', 'MED-114')->withTrashed()->get();
foreach ($asigs as $a) {
    echo "ID: {$a->id}, Nombre: {$a->nombre}, Plan: {$a->plan_estudios}, Borrado: " . ($a->deleted_at ? 'SÍ' : 'NO') . "\n";
    $count = DB::table('asignatura_carrera')->where('asignatura_id', $a->id)->count();
    echo "  - Relaciones en asignatura_carrera: $count\n";
}
