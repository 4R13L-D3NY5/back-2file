<?php

use App\Models\Grupo;
use App\Models\Cronograma;
use App\Models\Asignatura;
use App\Models\Carrera;
use App\Models\Sede;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- DEBUG TALLER DE REDES START ---\n";

// 1. Find the Asignatura
$name = 'TALLER DE REDES';
$asignatura = Asignatura::where('nombre', 'like', "%$name%")->first();

if (!$asignatura) {
    echo "Asignatura '$name' not found.\n";
    die();
}

echo "Asignatura: " . $asignatura->nombre . " (ID: " . $asignatura->id . ")\n";

// 2. Check Carrera/Sede links
echo "\n--- Carrera/Sede Links ---\n";
$links = DB::table('asignatura_carrera')
    ->where('asignatura_id', $asignatura->id)
    ->get();

foreach ($links as $link) {
    $carrera = Carrera::find($link->carrera_id);
    $sede = Sede::find($link->sede_id);
    echo "Sede: " . ($sede->nombre ?? $link->sede_id) . " | Carrera: " . ($carrera->nombre ?? $link->carrera_id) . "\n";
}

// 3. Find Groups
echo "\n--- Groups ---\n";
$grupos = Grupo::where('asignatura_id', $asignatura->id)->with('docente')->get();

foreach ($grupos as $grupo) {
    echo "Grupo ID: " . $grupo->id . " | Nombre: " . $grupo->nombre . " | Docente: " . ($grupo->docente->nombre_completo ?? 'NULL') . "\n";
    
    // 4. Check ALL Cronogramas
    $allCronos = Cronograma::where('grupo_id', $grupo->id)->get();
    echo "  Total Cronogramas: " . $allCronos->count() . "\n";
    foreach ($allCronos as $ac) {
        echo "    * ID: " . $ac->id . " | Fecha: " . $ac->fecha . " (raw)\n";
    }

    $startDate = '2026-02-09';
    $endDate = '2026-02-15';
    
    $cronos = Cronograma::where('grupo_id', $grupo->id)
        ->whereBetween('fecha', [$startDate, $endDate])
        ->with('seguimientos')
        ->get();
        
    echo "  Cronogramas in range ($startDate to $endDate): " . $cronos->count() . "\n";
    foreach ($cronos as $c) {
        $hasSeguimiento = $c->seguimientos->isNotEmpty();
        echo "    - Fecha: " . $c->fecha . " | CronoID: " . $c->id . " | Seguimiento: " . ($hasSeguimiento ? 'YES' : 'NO') . "\n";
        if ($hasSeguimiento) {
            foreach ($c->seguimientos as $s) {
                 echo "      * SegID: " . $s->id . " | Cumplido: " . $s->cumplido . " | Estado: " . ($s->estado_cumplimiento ?? 'NULL') . "\n";
            }
        }
    }
}

// 5. Search specifically for those cronograma IDs
echo "\n--- Specific Cronogramas (85880, 85879) ---\n";
$ids = [85880, 85879];
foreach ($ids as $id) {
    $c = Cronograma::find($id);
    if ($c) {
        echo "Crono ID: " . $c->id . " | Fecha: " . $c->fecha . " | Grupo ID: " . $c->grupo_id . " | Asignatura ID: " . ($c->asignatura_id ?? 'NULL') . "\n";
    } else {
        echo "Crono ID: $id NOT FOUND in DB.\n";
    }
}

echo "--- DEBUG END ---\n";
