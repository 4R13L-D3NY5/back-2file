<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('asignatura_carrera')
    ->where('asignatura_id', 1611)
    ->where('carrera_id', 34)
    ->get();

echo "Asignatura ID | Carrera ID | Semestre | Sede ID\n";
echo str_repeat('-', 40) . "\n";
foreach ($rows as $r) {
    echo "{$r->asignatura_id} | {$r->carrera_id} | {$r->semestre} | {$r->sede_id}\n";
}
echo "\nTotal rows: " . $rows->count() . "\n";
