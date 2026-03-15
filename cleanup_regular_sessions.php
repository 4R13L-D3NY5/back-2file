<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Grupo;
use App\Models\Horario;

echo "Starting cleanup of 'REGULAR' sessions...\n";

DB::transaction(function() {
    $regularGroups = Grupo::where('tipo', 'REGULAR')->get();
    echo "Found " . $regularGroups->count() . " groups of type 'REGULAR'.\n";

    $mergedCount = 0;
    $renamedCount = 0;
    $deletedSchedules = 0;

    foreach ($regularGroups as $reg) {
        // Find if an equivalent TEORICO or PRACTICO group exists
        $match = Grupo::where([
            'gestion' => $reg->gestion,
            'asignatura_id' => $reg->asignatura_id,
            'carrera_id' => $reg->carrera_id,
            'nombre' => $reg->nombre,
            'sede_id' => $reg->sede_id
        ])
        ->where('id', '<>', $reg->id)
        ->whereIn('tipo', ['TEORICO', 'PRACTICO'])
        ->first();

        if ($match) {
            // MERGE: Move schedules to the matched group
            $horarios = Horario::where('grupo_id', $reg->id)->get();
            foreach ($horarios as $h) {
                // Check if the matched group already has a schedule at this time/day
                $duplicateSchedule = Horario::where([
                    'grupo_id' => $match->id,
                    'dia' => $h->dia,
                    'hora_inicio' => $h->hora_inicio
                ])->exists();

                if (!$duplicateSchedule) {
                    $h->update(['grupo_id' => $match->id]);
                } else {
                    $h->delete(); // It's already there
                    $deletedSchedules++;
                }
            }

            // Move Seguimientos (if any)
            $seguimientos = DB::table('seguimientos')->where('grupo_id', $reg->id)->get();
            foreach ($seguimientos as $s) {
                $dupSeg = DB::table('seguimientos')
                    ->where('grupo_id', $match->id)
                    ->where('cronograma_id', $s->cronograma_id)
                    ->exists();
                
                if (!$dupSeg) {
                    DB::table('seguimientos')->where('id', $s->id)->update(['grupo_id' => $match->id]);
                } else {
                    DB::table('seguimientos')->where('id', $s->id)->delete();
                }
            }
            
            // Delete the redundant regular group
            $reg->forceDelete();
            $mergedCount++;
        } else {
            // RENAME: No match found, just make it a TEORICO group
            $reg->update(['tipo' => 'TEORICO']);
            $renamedCount++;
        }
    }

    echo "Cleanup finished:\n";
    echo "- Groups merged and deleted: $mergedCount\n";
    echo "- Groups renamed to TEORICO: $renamedCount\n";
    echo "- Duplicate schedules removed: $deletedSchedules\n";
});
