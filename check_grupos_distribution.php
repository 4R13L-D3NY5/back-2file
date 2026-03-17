<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$results = DB::table('grupos')
    ->join('asignaturas', 'asignaturas.id', '=', 'grupos.asignatura_id')
    ->where('asignaturas.codigo', 'ENF-111')
    ->select('asignaturas.id', 'asignaturas.nombre', 'asignaturas.plan_estudios', DB::raw('COUNT(grupos.id) as grupos_count'))
    ->groupBy('asignaturas.id', 'asignaturas.nombre', 'asignaturas.plan_estudios')
    ->get();

foreach ($results as $r) {
    echo "Asignatura ID: $r->id, Nombre: $r->nombre, Plan: $r->plan_estudios, Grupos: $r->grupos_count\n";
}

// Also check docentes per asignatura
$docentes = DB::table('docentes')
    ->join('grupos', 'docentes.id', '=', 'grupos.docente_id')
    ->join('asignaturas', 'asignaturas.id', '=', 'grupos.asignatura_id')
    ->where('asignaturas.codigo', 'ENF-111')
    ->select('docentes.id', 'docentes.nombre_completo', 'asignaturas.plan_estudios')
    ->distinct()
    ->get();

echo "\nDocentes por plan:\n";
foreach ($docentes as $d) {
    echo "Docente: $d->nombre_completo, Plan: $d->plan_estudios\n";
}