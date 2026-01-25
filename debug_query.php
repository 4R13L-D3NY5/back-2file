<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;

echo "--- DEBUG Query Block (Fixed) ---\n";

try {
    $codigo = 'SOME-CODE';

    $query = Asignatura::where('codigo', $codigo)
        ->whereHas('carreras', function ($q) {
            // Explicit qualification
            $q->where('asignatura_carrera.sede_id', 1);
        });

    echo "Generated SQL: " . $query->toSql() . "\n";
    $query->first();
    echo "OK.\n";
} catch (\Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}
