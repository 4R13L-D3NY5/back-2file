<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$asigs = DB::table('asignaturas')->where('codigo', 'LIKE', 'ENF-115%')->get();
foreach ($asigs as $a) {
    echo "[{$a->id}] {$a->codigo} | {$a->nombre} | Plan: {$a->plan_estudios}\n";
}
