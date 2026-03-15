<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$codes = ['ELC-213', 'ENF-115'];

foreach ($codes as $code) {
    echo "=== ANALIZANDO CANDIDATOS PARA: $code ===\n";
    $cands = DB::table('asignaturas as a')
        ->where('a.codigo', $code)
        ->whereNull('a.deleted_at')
        ->get();

    foreach ($cands as $c) {
        echo "ID: {$c->id}, Nombre: {$c->nombre}, Plan: " . ($c->plan_estudios ?: 'N') . "\n";
        
        // Ver carreras asociadas
        $carreras = DB::table('asignatura_carrera as ac')
            ->join('carreras as car', 'ac.carrera_id', '=', 'car.id')
            ->join('sedes as s', 'ac.sede_id', '=', 's.id')
            ->where('ac.asignatura_id', $c->id)
            ->select('car.nombre as carrera', 's.nombre as sede')
            ->get();
            
        foreach ($carreras as $car) {
            echo "   - Carrera: {$car->carrera}, Sede: {$car->sede}\n";
        }
    }
    echo "\n";
}
