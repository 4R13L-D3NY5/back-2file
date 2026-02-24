<?php

use App\Models\Grupo;
use App\Models\Asignatura;
use App\Models\Cronograma;
use App\Models\Seguimiento;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- TEST GENERATE WEEKLY REPORT ---\n";

$carreraId = 1; // Systems?
$sedeId = 1;    // Cochabamba?
$fechaInicio = '2026-02-09';

$startDate = Carbon::parse($fechaInicio)->startOfWeek();
$endDate = $startDate->copy()->endOfWeek();

$baseDate = Carbon::create(2026, 2, 9)->startOfWeek();
$weekNum = $startDate->diffInWeeks($baseDate) + 1;

echo "Consulting Semana: $weekNum | Range: {$startDate->toDateString()} to {$endDate->toDateString()}\n";

$grupos = Grupo::whereHas('asignatura.carreras', function ($q) use ($carreraId, $sedeId) {
    $q->where('carreras.id', $carreraId)
      ->where('asignatura_carrera.sede_id', $sedeId);
})
->with(['asignatura', 'docente', 'cronogramas' => function ($cq) use ($weekNum) {
        $cq->where('semana_academica', $weekNum);
    }, 'seguimientos' => function ($sq) use ($startDate, $endDate) {
        $sq->whereBetween('fecha', [$startDate->toDateString(), $endDate->toDateString()]);
    }])
    ->get();

echo "Groups found: " . $grupos->count() . "\n";

if ($grupos->count() > 0) {
    $sample = $grupos->first();
    echo "Sample Group: " . $sample->nombre . " | Asig: " . $sample->asignatura->nombre . "\n";
    echo "  Planned Cronos in week: " . $sample->cronogramas->count() . "\n";
    echo "  Executed Seguimientos in week: " . $sample->seguimientos->count() . "\n";
}

echo "--- END ---\n";
