<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$c10 = DB::table('asignaturas')->where('created_at', '>=', now()->subHours(10))->count();
$c24 = DB::table('asignaturas')->where('created_at', '>=', now()->subHours(24))->count();
$c48 = DB::table('asignaturas')->where('created_at', '>=', now()->subHours(48))->count();

echo "Asignaturas creadas ultimas 10 h: $c10\n";
echo "Asignaturas creadas ultimas 24 h: $c24\n";
echo "Asignaturas creadas ultimas 48 h: $c48\n";
