<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$res = DB::table('asignaturas')->where('nombre', 'LIKE', '%TECNICAS%ENFERMERIA%')->get();
echo json_encode($res, JSON_PRETTY_PRINT);
