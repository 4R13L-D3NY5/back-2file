<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Grupos con docente (plan N):\n";
$gruposCon = DB::table('grupos')
    ->join('asignaturas', 'asignaturas.id', '=', 'grupos.asignatura_id')
    ->where('asignaturas.codigo', 'ENF-111')
    ->where('asignaturas.plan_estudios', 'N')
    ->whereNotNull('grupos.docente_id')
    ->select('grupos.id', 'grupos.nombre', 'grupos.docente_id', 'asignaturas.nombre as asignatura_nombre')
    ->limit(5)
    ->get();
foreach ($gruposCon as $g) {
    echo "ID: $g->id, Nombre: $g->nombre, Docente ID: $g->docente_id\n";
}

echo "\nGrupos sin docente (plan N):\n";
$gruposSin = DB::table('grupos')
    ->join('asignaturas', 'asignaturas.id', '=', 'grupos.asignatura_id')
    ->where('asignaturas.codigo', 'ENF-111')
    ->where('asignaturas.plan_estudios', 'N')
    ->whereNull('grupos.docente_id')
    ->select('grupos.id', 'grupos.nombre', 'asignaturas.nombre as asignatura_nombre')
    ->limit(5)
    ->get();
foreach ($gruposSin as $g) {
    echo "ID: $g->id, Nombre: $g->nombre\n";
}

echo "\nDocentes disponibles (sede 1):\n";
$docentes = DB::table('docentes')->where('sede_id', 1)->select('id', 'nombre_completo', 'ci')->limit(5)->get();
foreach ($docentes as $d) {
    echo "ID: $d->id, Nombre: $d->nombre_completo, CI: $d->ci\n";
}