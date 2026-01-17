<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use App\Models\Sede;
use App\Models\Asignatura;

$sede = Sede::where('nombre', 'like', '%Cobija%')->first();

if (!$sede) {
    echo "Sede Cobija no encontrada.\n";
    exit;
}

echo "Sede: {$sede->nombre} (ID: {$sede->id})\n";

$asignaturas = Asignatura::where('nombre', 'like', '%Redes%')
    ->whereHas('carrera', function ($q) use ($sede) {
        $q->where('sede_id', $sede->id);
    })
    ->with('carrera')
    ->get();

echo "Asignaturas encontradas: " . $asignaturas->count() . "\n";

foreach ($asignaturas as $a) {
    echo "[{$a->id}] {$a->codigo} - {$a->nombre} | Carrera: {$a->carrera->nombre} ({$a->carrera->codigo})\n";
}
