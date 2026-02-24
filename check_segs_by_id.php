<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Seguimiento;

foreach ([1204, 1205, 13234, 13235, 153] as $id) {
    echo "Grupo ID: $id\n";
    $segs = Seguimiento::where('grupo_id', $id)->get();
    echo "  Registros: " . $segs->count() . "\n";
    if ($segs->count() > 0) {
        foreach ($segs as $s) {
            echo "    - Seg ID: {$s->id} | Fecha: {$s->fecha}\n";
        }
    }
}
