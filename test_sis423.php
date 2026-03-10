<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$a = App\Models\Asignatura::with([
    'unidades.temas.planificacionPersonal',
    'unidades.temas.logros.bancoPreguntas',
    'bibliografias',
    'cronogramas'
])->where('codigo', 'SIS-423')->first();

if (!$a) {
    echo "Asignatura no encontrada.\n";
    exit;
}

// Global Calculation (Simulating index response)
echo "\n=== GLOBAL (NO USER ID) ===\n";
echo "Progreso: " . $a->progreso . "\n";
echo "Indicadores: " . json_encode($a->indicadores_documentacion, JSON_PRETTY_PRINT) . "\n";

// Docente Calculation (Simulating inner edit page & specific user)
// Get the docente ID for testing
$docente = $a->docentes->first();
$userId = $docente ? $docente->user_id : null;

echo "\n=== CON USER ID ($userId) ===\n";
if ($userId) {
    echo "Progreso: " . $a->getProgresoPorDocente($userId) . "\n";
    echo "Indicadores: " . json_encode($a->getIndicadoresDocumentacionPorDocente($userId), JSON_PRETTY_PRINT) . "\n";
} else {
    echo "No docente found.\n";
}
