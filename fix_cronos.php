<?php

use App\Models\Grupo;
use App\Models\Cronograma;
use App\Models\Tema;
use App\Models\Asignatura;
use App\Models\Asistencia;
use App\Models\Matricula;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- FIXING CRONOGRAMAS ---\n";

$grupo = Grupo::find(343);
if (!$grupo) die("Grupo 343 not found.\n");

// Force clean old cronos for this group to avoid dupes/mess
Cronograma::where('grupo_id', 343)->delete();
echo "Cleaned old cronogramas.\n";

$temas = Tema::whereHas('unidad', function($q) use ($grupo) {
    $q->where('asignatura_id', $grupo->asignatura_id);
})->orderBy('orden')->get();

echo "Temas found: " . $temas->count() . "\n";

$startDate = Carbon::now()->startOfWeek(); // Start THIS week to make it "Semana 1" = Current Week logic easier for user
echo "Start Date: " . $startDate->toDateString() . "\n";

$matriculas = Matricula::where('asignatura_id', $grupo->asignatura_id)->get();
echo "Matriculas found: " . $matriculas->count() . "\n";

foreach ($temas as $index => $tema) {
    $date = $startDate->copy()->addDays($index * 2); // Mon, Wed, Fri...
    
    $crono = Cronograma::create([
        'grupo_id' => $grupo->id,
        'tema_id' => $tema->id,
        'asignatura_id' => $grupo->asignatura_id, // Explicitly set this time
        'fecha' => $date,
        'hora_inicio' => '08:00',
        'hora_fin' => '10:00',
        'tipo' => 'TEORICA',
        'estado' => 'FINALIZADO',
        'observaciones' => 'Clase regenerada V7',
        'numero_sesion' => $index + 1
    ]);
    
    echo "Created Crono: {$crono->id} for {$date->toDateString()}\n";
    
    // Asistencias
    foreach ($matriculas as $i => $mat) {
        Asistencia::create([
            'cronograma_id' => $crono->id,
            'matricula_id' => $mat->id,
            'asistio' => ($i < 15) // First 15 present
        ]);
    }
}

echo "--- FIX COMPLETE ---\n";
