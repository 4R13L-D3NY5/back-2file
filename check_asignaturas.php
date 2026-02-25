<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;

$asignaturas = Asignatura::where('codigo', 'SIS-325')->get();

echo "ID | Código | Nombre\n";
echo str_repeat('-', 40) . "\n";
foreach ($asignaturas as $a) {
    echo "{$a->id} | {$a->codigo} | {$a->nombre}\n";
}
echo "\nTotal rows: " . $asignaturas->count() . "\n";
