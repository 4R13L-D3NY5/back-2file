<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Query a teacher's subject in backup to see actual data depth
$dbBackup = 'academico_backup';
$dbCurrent = 'academico';

// ELC-213 from Tania in backup (ID 1354 or similar?)
// Let's find an asignatura from Tania
$docB = DB::table("$dbBackup.docentes")->where('ci', '4534773')->first();
$grupoB = DB::table("$dbBackup.grupos")->where('docente_id', $docB->id)->first();
$asigId = $grupoB->asignatura_id;

echo "Muestra de contenido en BACKUP para asignatura_id: $asigId\n";

$unidades = DB::table("$dbBackup.unidades")->where('asignatura_id', $asigId)->get();
echo "Unidades: " . count($unidades) . "\n";
foreach($unidades as $u) {
    $temas = DB::table("$dbBackup.temas")->where('unidad_id', $u->id)->count();
    echo "- Unidad {$u->id}: $temas temas\n";
}

$evals = DB::table("$dbBackup.evaluaciones")->where('asignatura_id', $asigId)->get();
echo "Evaluaciones: " . count($evals) . "\n";
foreach($evals as $ev) {
    // Check what tables depend on evaluacion
    // maybe 'preguntas'?
    // Let's check if 'preguntas' exists
    $preguntasCount = 0;
    try {
        $preguntasCount = DB::table("$dbBackup.preguntas")->where('evaluacion_id', $ev->id)->count();
    } catch (\Exception $e) {}
    echo "- Evaluacion {$ev->id}: $preguntasCount preguntas\n";
}

$biblio = DB::table("$dbBackup.bibliografias")->where('asignatura_id', $asigId)->count();
echo "Bibliografias: $biblio\n";

// Check if any scoped asignaturas left orphaned records in Current
// Find orphaned unidades
$orphanedUnidades = DB::table("$dbCurrent.unidades")
    ->leftJoin("$dbCurrent.asignaturas", 'unidades.asignatura_id', '=', 'asignaturas.id')
    ->whereNull('asignaturas.id')
    ->count();

echo "Unidades huérfanas en CURRENT: $orphanedUnidades\n";
