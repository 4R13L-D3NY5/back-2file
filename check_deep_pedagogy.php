<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$tabs = [
    'asignaturas', 'planificaciones_personales', 'logros_esperados', 'indicadores', 'seguimientos', 'seguimiento_semanal'
];

foreach ($tabs as $t) {
    try {
        $cols = DB::select("SHOW COLUMNS FROM academico.{$t}");
        echo "\n=== $t ===\n";
        $cNames = [];
        foreach($cols as $c) $cNames[] = "{$c->Field} ({$c->Type})";
        echo implode("\n", $cNames) . "\n";
    } catch (\Exception $e) {
        echo "\n[ERROR] Tabla $t no existe o no se puede leer.\n";
    }
}
