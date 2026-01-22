<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;
use Illuminate\Support\Facades\DB;

// Simulate request filters
$sedeId = 1;
$carreraId = 10;
$semestre = null; // Assuming no semestre filter applied

echo "Checking for Sede $sedeId, Carrera $carreraId...\n";

try {
    $count = Asignatura::whereHas('carreras', function ($q) use ($sedeId, $carreraId, $semestre) {
        if ($sedeId) $q->where('asignatura_carrera.sede_id', $sedeId);
        if ($carreraId) $q->where('carreras.id', $carreraId);
        if ($semestre) $q->where('asignatura_carrera.semestre', $semestre);
    })->count();

    echo "Count: " . $count . "\n";

    // Check without table alias if count is 0
    if ($count == 0) {
        echo "Attempting check without table alias...\n";
        $count2 = Asignatura::whereHas('carreras', function ($q) use ($sedeId, $carreraId) {
            // Try standard pivot access if aliases fail (though whereHas joins pivot)
            $q->where('sede_id', $sedeId);
        })->count();
        echo "Count (simple pivot check): $count2\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
