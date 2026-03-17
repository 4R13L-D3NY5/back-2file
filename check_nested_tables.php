<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = [
    'preguntas', 'opciones', 'respuestas', 'evaluacion_preguntas', 'recursos', 'recursos_temas'
];

foreach ($tables as $t) {
    try {
        $cols = DB::select("SHOW COLUMNS FROM academico.{$t}");
        echo "Tabla $t existe. Columnas: ";
        $cNames = [];
        foreach($cols as $c) $cNames[] = $c->Field;
        echo implode(", ", $cNames) . "\n";
    } catch (\Exception $e) {
        // Table doesn't exist
    }
}
