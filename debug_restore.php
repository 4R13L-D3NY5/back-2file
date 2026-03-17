<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

// 1. Buscar a la docente en el backup
$docente = DB::table("$dbBackup.docentes")->where('ci', '4534773')->first();

if (!$docente) {
    die("Docente 4534773 no encontrada en backup.\n");
}

echo "Docente: {$docente->nombre_completo} (ID Backup: {$docente->id})\n";

// 2. Ver sus grupos en el backup con su asignatura
$gruposB = DB::table("$dbBackup.grupos as g")
    ->join("$dbBackup.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
    ->where('g.docente_id', $docente->id)
    ->select('g.*', 'a.codigo', 'a.nombre as asig_nombre')
    ->get();

echo "\nGrupos en BACKUP:\n";
foreach ($gruposB as $g) {
    echo "- [ID:{$g->id}] {$g->codigo} | {$g->asig_nombre}\n";
    echo "  Nombre: '{$g->nombre}' | Tipo: '{$g->tipo}' | Carrera: {$g->carrera_id} | Sede: {$g->sede_id}\n";
    
    // Intentar buscar en DB actual
    $codigoBase = $g->codigo;
    if (preg_match('/^([A-Z]{2,5}-[0-9]{3})-.+/', $g->codigo, $m)) {
        $codigoBase = $m[1];
    }
    
    $match = DB::table("$dbCurrent.grupos as g")
        ->join("$dbCurrent.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
        ->where('a.codigo', $codigoBase)
        ->where('g.nombre', $g->nombre)
        ->where('g.tipo', $g->tipo)
        ->where('g.carrera_id', $g->carrera_id)
        ->where('g.sede_id', $g->sede_id)
        ->select('g.id', 'g.docente_id', 'a.codigo as actual_codigo')
        ->first();
        
    if ($match) {
        echo "  [MATCH ENCONTRADO] ID Actual: {$match->id} | Docente Actual: " . ($match->docente_id ?: 'NULL') . "\n";
    } else {
        echo "  [!!!] SIN MATCH en DB actual.\n";
        // Ver grupos de esa asignatura en la actual para ver diferencia
        $otros = DB::table("$dbCurrent.grupos as g")
            ->join("$dbCurrent.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
            ->where('a.codigo', $codigoBase)
            ->where('g.carrera_id', $g->carrera_id)
            ->where('g.sede_id', $g->sede_id)
            ->select('g.nombre', 'g.tipo', 'g.id')
            ->get();
        echo "  Grupos existentes para esa materia en la actual:\n";
        foreach($otros as $o) {
            echo "    - '{$o->nombre}' (Tipo: '{$o->tipo}') [ID:{$o->id}]\n";
        }
    }
    echo "--------------------------------------------------\n";
}
