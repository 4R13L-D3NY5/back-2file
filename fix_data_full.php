<?php

use App\Models\Grupo;
use App\Models\Cronograma;
use App\Models\Tema;
use App\Models\Unidad;
use App\Models\Asignatura;
use App\Models\Asistencia;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\Sede;
use App\Models\Docente;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

DB::beginTransaction();
try {
    echo "--- FULL DATA FIX ---\n";

    // 1. Ensure Asignatura exists
    $asig = Asignatura::firstOrCreate(
        ['codigo' => 'SIS-334'],
        ['nombre' => 'LENGUAJES DE PROGRAMACIÓN DE ÚLTIMA GENERACIÓN', 'semestre' => 6]
    );
    echo "Asignatura: {$asig->id}\n";

    // 2. Ensure Unidad & Temas exist
    if ($asig->unidades()->count() == 0) {
        $unidad = Unidad::create([
            'asignatura_id' => $asig->id,
            'titulo' => 'Unidad 1: Fundamentos',
            'numero' => 1,
            'objetivo' => 'Fix Data',
            'contenidos' => 'Fix Data', // Assuming simple field if schema allows, or fillable defaults
            'tipo' => 'TEORIA' 
        ]);
        
        for ($i = 1; $i <= 5; $i++) {
            Tema::create([
                'unidad_id' => $unidad->id,
                'titulo' => "Tema $i: Concepto $i",
                'numero' => $i,
                'orden' => $i,
                'tipo' => 'TEORIA'
            ]);
        }
        echo "Created Unidad & 5 Temas.\n";
    }

    $temas = $asig->temas; // Assumes relation works (through unidades) or direct if model changed
    // Let's rely on Unidad->temas for loop if Asignatura->temas relation is tricky
    // But Asignatura->temas() usually hasManyThrough or requires manual fetch.
    // In ReporteController we used $asig->unidades->pluck('temas')...
    
    // Explicit fetch for script
    $temas = Tema::whereHas('unidad', fn($q) => $q->where('asignatura_id', $asig->id))->get();
    echo "Temas count: " . $temas->count() . "\n";

    // 3. Ensure Students & Matriculas
    if (Matricula::where('asignatura_id', $asig->id)->count() == 0) {
        for ($k = 1; $k <= 15; $k++) {
            $est = Estudiante::firstOrCreate(['codigo' => "FIX-$k"], ['nombres' => "Student $k", 'apellidos' => 'Fix']);
            Matricula::create([
                'gestion' => '1-2024',
                'estudiante_id' => $est->id,
                'asignatura_id' => $asig->id
            ]);
        }
        echo "Created 15 Matriculas.\n";
    }

    // 4. Ensure Grupo (Harold)
    $docente = Docente::where('nombre_completo', 'like', '%HAROLD%')->first();
    $sede = Sede::first(); // Take any site
    
    $grupo = Grupo::updateOrCreate(
        ['asignatura_id' => $asig->id, 'docente_id' => $docente->id],
        ['nombre' => 'Grupo Fix', 'gestion' => '1-2024', 'sede_id' => $sede->id]
    );
    echo "Grupo: {$grupo->id}\n";
    
    // 5. Cronogramas (Clean & Create)
    Cronograma::where('grupo_id', $grupo->id)->delete();
    
    $startDate = Carbon::now()->startOfWeek(); 
    
    foreach ($temas as $index => $tema) {
        $date = $startDate->copy()->addDays($index * 2);
        
        $crono = Cronograma::create([
            'grupo_id' => $grupo->id,
            'tema_id' => $tema->id,
            'asignatura_id' => $asig->id,
            'fecha' => $date,
            'hora_inicio' => '08:00:00',
            'hora_fin' => '10:00:00',
            'tipo' => 'TEORICA',
            'estado' => 'FINALIZADO',
            'numero_sesion' => $index + 1
        ]);
        
        // Asistance
        $mats = Matricula::where('asignatura_id', $asig->id)->get();
        foreach ($mats as $idx => $m) {
            Asistencia::create([
                'cronograma_id' => $crono->id,
                'matricula_id' => $m->id,
                'asistio' => ($idx < 12)
            ]);
        }
    }
    echo "Cronogramas regenerated for THIS WEEK.\n";

    DB::commit();
    echo "SUCCESS.";
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
