<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$cols = DB::select("SHOW COLUMNS FROM academico.cronogramas");
foreach ($cols as $c) echo "{$c->Field}\n";

// Revisar Tania
$docB = DB::table('academico_backup.docentes')->where('ci', '4534773')->first();
$gruposB = DB::table('academico_backup.grupos')->where('docente_id', $docB->id)->get();
foreach($gruposB as $gb) {
    $cronoB = DB::table('academico_backup.cronogramas')->where('grupo_id', $gb->id)->count();
    $asigB = DB::table('academico_backup.asignaturas')->where('id', $gb->asignatura_id)->first();
    echo "Backup Grupo {$gb->nombre} (Asig {$asigB->nombre}): $cronoB cronogramas\n";
    
    // Check in current
    $grupoC = DB::table('academico.grupos')->where('docente_id', DB::table('academico.docentes')->where('ci', '4534773')->value('id'))
        ->where('nombre', $gb->nombre)->where('gestion', $gb->gestion)->where('sede_id', $gb->sede_id)
        ->first();
    if ($grupoC) {
        $cronoC = DB::table('academico.cronogramas')->where('grupo_id', $grupoC->id)->count();
        $asigC = DB::table('academico.asignaturas')->where('id', $grupoC->asignatura_id)->first();
        echo " -> Current Grupo {$grupoC->nombre} (Asig {$asigC->nombre}): $cronoC cronogramas\n";
        
        if ($cronoB > 0 && $cronoC == 0) {
            echo "   !!! FALTAN CRONOGRAMAS !!!\n";
            // Check why they didn't restore
            $cb = DB::table('academico_backup.cronogramas')->where('grupo_id', $gb->id)->first();
            print_r($cb);
        }
    }
}
