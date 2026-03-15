<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbBackup  = 'academico_backup';

echo "=== BUSCANDO EN BACKUP ===\n";
$names = ['PROBABILIDAD', 'TECNICAS DE ENFERMERIA'];
foreach ($names as $name) {
    echo "\nBuscando: $name\n";
    $res = DB::table("$dbBackup.asignaturas")->where('nombre', 'LIKE', "%$name%")->get();
    foreach ($res as $r) {
        echo "Match: ID: {$r->id}, Codigo: {$r->codigo}, Nombre: {$r->nombre}, Plan: {$r->plan_estudios}\n";
    }
}

echo "\n=== BUSCANDO GRUPOS DE TANIA (4534773) EN BACKUP CON NOMBRES ===\n";
$docB = DB::table("$dbBackup.docentes")->where('ci', '4534773')->first();
if ($docB) {
    $gs = DB::table("$dbBackup.grupos as g")
        ->join("$dbBackup.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
        ->where('g.docente_id', $docB->id)
        ->select('a.codigo', 'a.nombre', 'g.nombre as grupo')
        ->get();
    foreach ($gs as $g) {
        echo "Tania: [{$g->codigo}] {$g->nombre} (Grupo: {$g->grupo})\n";
    }
}
