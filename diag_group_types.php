<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$stats = DB::table('grupos')
    ->whereNull('deleted_at')
    ->select('tipo', DB::raw('count(*) as total'))
    ->groupBy('tipo')
    ->get();

echo "Group types stats:\n";
foreach ($stats as $stat) {
    echo "- {$stat->tipo}: {$stat->total}\n";
}

$duplicates = DB::table('grupos as g1')
    ->join('grupos as g2', function($join) {
        $join->on('g1.gestion', '=', 'g2.gestion')
             ->on('g1.asignatura_id', '=', 'g2.asignatura_id')
             ->on('g1.carrera_id', '=', 'g2.carrera_id')
             ->on('g1.nombre', '=', 'g2.nombre')
             ->on('g1.sede_id', '=', 'g2.sede_id')
             ->on('g1.id', '<>', 'g2.id');
    })
    ->where('g1.tipo', 'REGULAR')
    ->where('g2.tipo', 'TEORICO')
    ->select('g1.id as id1', 'g2.id as id2', 'g1.nombre as grupo', 'g1.asignatura_id')
    ->get();

echo "\nPotential duplicates (REGULAR vs TEORICO):\n";
echo "Total found: " . count($duplicates) . "\n";

$duplicatesPractico = DB::table('grupos as g1')
    ->join('grupos as g2', function($join) {
        $join->on('g1.gestion', '=', 'g2.gestion')
             ->on('g1.asignatura_id', '=', 'g2.asignatura_id')
             ->on('g1.carrera_id', '=', 'g2.carrera_id')
             ->on('g1.nombre', '=', 'g2.nombre')
             ->on('g1.sede_id', '=', 'g2.sede_id')
             ->on('g1.id', '<>', 'g2.id');
    })
    ->where('g1.tipo', 'REGULAR')
    ->where('g2.tipo', 'PRACTICO')
    ->select('g1.id as id1', 'g2.id as id2', 'g1.nombre as grupo', 'g1.asignatura_id')
    ->get();

echo "\nPotential duplicates (REGULAR vs PRACTICO):\n";
echo "Total found: " . count($duplicatesPractico) . "\n";
foreach ($duplicatesPractico->take(5) as $dup) {
    $asignatura = DB::table('asignaturas')->where('id', $dup->asignatura_id)->first();
    echo "- Subject [{$asignatura->codigo}] '{$asignatura->nombre}', Group '{$dup->grupo}'\n";
}
