<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$res = DB::table('asignaturas')->where('codigo', 'LIKE', 'ENF-115%')->get(['id', 'codigo', 'nombre', 'created_at', 'plan_estudios']);
echo json_encode($res, JSON_PRETTY_PRINT);

echo "\nWindow check (120h):\n";
echo "Now: " . now() . "\n";
echo "120h ago: " . now()->subHours(120) . "\n";
