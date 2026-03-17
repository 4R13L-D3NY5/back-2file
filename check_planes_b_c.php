<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$asigB = DB::table("academico_backup.asignaturas")->where('codigo', 'LIKE', 'ELC-213%')->where('nombre', 'PROBABILIDAD Y ESTADISTICA')->first();
$asigC = DB::table("academico.asignaturas")->where('codigo', 'ELC-213')->where('nombre', 'PROBABILIDAD Y ESTADISTICA')->first();

echo "Backup  Plan: " . ($asigB->plan_estudios ?? 'NULL') . "\n";
echo "Current Plan: " . ($asigC->plan_estudios ?? 'NULL') . "\n";
