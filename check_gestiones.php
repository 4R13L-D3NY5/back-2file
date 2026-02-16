<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Check distinct gestion_academica values
$gestiones = DB::table('asignaturas')
    ->select('gestion_academica', DB::raw('count(*) as total'))
    ->groupBy('gestion_academica')
    ->get();

echo "Gestiones existentes:\n";
foreach ($gestiones as $g) {
    echo "  '{$g->gestion_academica}' => {$g->total} asignaturas\n";
}

// Check fecha_inicio_clases status
$conFecha = DB::table('asignaturas')->whereNotNull('fecha_inicio_clases')->count();
$sinFecha = DB::table('asignaturas')->whereNull('fecha_inicio_clases')->count();
echo "\nCon fecha_inicio_clases: $conFecha\n";
echo "Sin fecha_inicio_clases: $sinFecha\n";

// Show a sample with fecha set
$sample = DB::table('asignaturas')->whereNotNull('fecha_inicio_clases')->first();
if ($sample) {
    echo "\nEjemplo con fecha: ID={$sample->id}, gestion={$sample->gestion_academica}, fecha={$sample->fecha_inicio_clases}\n";
}
