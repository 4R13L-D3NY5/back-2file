<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sys = app(\App\Services\MateriasComunesSyncService::class);

foreach ([1579, 1631, 2138] as $id) {
    if ($a = App\Models\Asignatura::find($id)) {
        echo "C" . $a->carrera_id . ' - ' . $a->codigo . ": " . $sys->calculateProgress($a) . "%\n";
    }
}
