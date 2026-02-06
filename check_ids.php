<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Horario;
use App\Models\Grupo;

echo "=== CHECKING ID POPULATION ===\n";

$totalHorarios = Horario::count();
$horariosWithId = Horario::whereNotNull('id_horario_api')->count();

echo "Total Horarios: $totalHorarios\n";
echo "Horarios with API ID: $horariosWithId\n";

if ($horariosWithId > 0) {
    echo "✅ IDs are populated. Safe to proceed with ID-based sync.\n";
    $sample = Horario::whereNotNull('id_horario_api')->first();
    echo "Sample ID: {$sample->id_horario_api} (Group ID: {$sample->grupo_id})\n";
} else {
    echo "⚠️ IDs are MISSING. detailed sync 'PlanningSyncService' must run first.\n";
}
