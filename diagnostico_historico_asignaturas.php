<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';

echo "Buscando TODAS las asignaturas (incluidas eliminadas) para ELC-213:\n";
$res1 = DB::table("$dbCurrent.asignaturas")->where('codigo', 'ELC-213')->get();
foreach ($res1 as $r) {
    echo "ID: {$r->id}, Nombre: {$r->nombre}, Plan: {$r->plan_estudios}, DeletedAt: {$r->deleted_at}\n";
}

echo "\nBuscando TODAS para ENF-115:\n";
$res2 = DB::table("$dbCurrent.asignaturas")->where('codigo', 'ENF-115')->get();
foreach ($res2 as $r) {
    echo "ID: {$r->id}, Nombre: {$r->nombre}, Plan: {$r->plan_estudios}, DeletedAt: {$r->deleted_at}\n";
}
