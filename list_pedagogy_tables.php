<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = DB::select('SHOW TABLES FROM academico');
$tableNames = [];
$dbName = 'Tables_in_academico'; // The property name in the object
foreach ($tables as $t) {
    $name = reset($t); // get first property
    $tableNames[] = $name;
}

echo "Tablas en academico:\n";
echo implode(", ", $tableNames) . "\n\n";

// Buscar columnas foreign key 'asignatura_id' o 'unidad_id'
foreach ($tableNames as $table) {
    $cols = DB::select("SHOW COLUMNS FROM academico.{$table}");
    foreach ($cols as $col) {
        if ($col->Field == 'asignatura_id' || $col->Field == 'unidad_id' || $col->Field == 'evaluacion_id' || $col->Field == 'pregunta_id') {
            echo "Tabla {$table} tiene {$col->Field}\n";
        }
    }
}
