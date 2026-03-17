<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$asigC = DB::table("academico.asignaturas")->where('codigo', 'ELC-213')->where('nombre', 'PROBABILIDAD Y ESTADISTICA')->first();
echo "ELC-213 created_at: " . $asigC->created_at . "\n";
echo "ELC-213 updated_at: " . $asigC->updated_at . "\n";
echo "Now is: " . now() . "\n";
echo "10 hours ago: " . now()->subHours(10) . "\n";
