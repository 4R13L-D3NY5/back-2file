<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$asigs = App\Models\Asignatura::where('nombre', 'like', '%TALLER%INGENIERIA%SOFTWARE%')->get();
foreach ($asigs as $a) {
    echo $a->id . ' - ' . $a->codigo . ' - ' . $a->nombre . "\n";
}
