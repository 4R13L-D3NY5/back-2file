<?php

use App\Models\LogroEsperado;
use App\Models\Tema;
use Illuminate\Support\Facades\DB;

// Load explicitly to avoid scoping issues
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Adjust ID based on user's context (Assuming ID from previous logs or recent activity)
// We'll search for the most recent Logros
$logros = LogroEsperado::with('indicadores', 'tema')->orderBy('id', 'desc')->take(5)->get();

echo "--- ULTIMOS 5 LOGROS ---\n";
foreach ($logros as $l) {
    echo "ID: {$l->id} | Tema ID: {$l->tema_id} | Desc: {$l->descripcion} | Periodo: '{$l->periodo}' | Inds: " . $l->indicadores->count() . "\n";
}

// Check columns of table
echo "\n--- COLUMNAS TABLA LOGROS ---\n";
$columns = DB::select('DESCRIBE logros_esperados');
foreach ($columns as $c) {
    echo "{$c->Field} ({$c->Type})\n";
}
