<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;
use App\Models\Sede;

echo "=== DEBUG ASIGNATURA VARIANTS ===\n";

$variants = Asignatura::where('codigo', 'like', 'ENF-114%')->get();

echo "Found " . $variants->count() . " variants for ENF-114:\n";
foreach ($variants as $a) {
    echo "ID: {$a->id} | Codigo: {$a->codigo} | Nombre: {$a->nombre}\n";
}

echo "\n=== SEDE CODES ===\n";
$sedes = Sede::all();
foreach ($sedes as $s) {
    echo "ID: {$s->id} | Codigo: {$s->codigo} | Nombre: {$s->nombre}\n";
}
