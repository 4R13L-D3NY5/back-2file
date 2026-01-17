<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use App\Models\Asignatura;
use Illuminate\Support\Facades\DB;

// Buscar una asignatura que tenga mas filas en pivot que docentes unicos
$asig = null;
$asignaturas = Asignatura::has('docentes')->get();

foreach ($asignaturas as $a) {
    $pivotCount = DB::table('asignatura_docente')->where('asignatura_id', $a->id)->count();
    $distinctDocentes = DB::table('asignatura_docente')->where('asignatura_id', $a->id)->distinct('docente_id')->count('docente_id');

    if ($pivotCount > $distinctDocentes) {
        $asig = $a;
        echo "Found Asignatura ID {$a->id} with {$pivotCount} pivot rows but {$distinctDocentes} unique docentes.\n";
        break;
    }
}

if (!$asig) {
    echo "No asignatura with multiple groups for same teacher found.\n";
    // Force create one for testing? No, read only verification.
    exit;
}

// Now load via Eloquent
$asig->load('docentes');
echo "Eloquent loaded docentes count: " . $asig->docentes->count() . "\n";

if ($asig->docentes->count() < $pivotCount) {
    echo "CONFIRMED: Eloquent HIDES duplicates.\n";
} else {
    echo "Eloquent returns all rows.\n";
}
