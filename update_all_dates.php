<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Update ALL asignaturas that don't have fecha_inicio_clases yet
$updated = DB::table('asignaturas')
    ->whereNull('fecha_inicio_clases')
    ->update(['fecha_inicio_clases' => '2026-02-09']);

echo "Actualizadas: $updated asignaturas con fecha_inicio_clases = 2026-02-09\n";

// Verify
$sinFecha = DB::table('asignaturas')->whereNull('fecha_inicio_clases')->count();
echo "Asignaturas aun sin fecha: $sinFecha\n";
