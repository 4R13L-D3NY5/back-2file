<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Grupo;
use App\Models\Horario;

echo "Starting cleanup of 'REGULAR' sessions (v2 - conflict-safe)...\n";

// First, verify the unique index columns by inspecting the DB
// Expected: grupos_sede_unico_idx covers (gestion, asignatura_id, carrera_id, nombre, tipo, sede_id) or similar
// We'll handle conflicts by doing a broad pre-check before renaming

$regularGroups = Grupo::where('tipo', 'REGULAR')->get();
echo "Found " . $regularGroups->count() . " groups of type 'REGULAR'.\n";

$mergedCount    = 0;
$renamedCount   = 0;
$deletedSchedules = 0;
$errorCount     = 0;

foreach ($regularGroups as $reg) {
    try {
        DB::transaction(function () use ($reg, &$mergedCount, &$renamedCount, &$deletedSchedules) {

            // --- STEP 1: Look for a TEORICO/PRACTICO match (same carrera_id) ---
            $match = Grupo::where([
                'gestion'       => $reg->gestion,
                'asignatura_id' => $reg->asignatura_id,
                'carrera_id'    => $reg->carrera_id,
                'nombre'        => $reg->nombre,
                'sede_id'       => $reg->sede_id,
            ])
            ->where('id', '<>', $reg->id)
            ->whereIn('tipo', ['TEORICO', 'PRACTICO'])
            ->first();

            // --- STEP 2: If not found by carrera, try WITHOUT carrera_id ---
            // This catches the case where the unique index doesn't include carrera_id
            // or the existing TEORICO has a different carrera.
            if (!$match) {
                $match = Grupo::where([
                    'gestion'       => $reg->gestion,
                    'asignatura_id' => $reg->asignatura_id,
                    'nombre'        => $reg->nombre,
                    'sede_id'       => $reg->sede_id,
                ])
                ->where('id', '<>', $reg->id)
                ->whereIn('tipo', ['TEORICO', 'PRACTICO'])
                ->first();
            }

            if ($match) {
                // MERGE: Move schedules to the matched group
                $horarios = Horario::where('grupo_id', $reg->id)->get();
                foreach ($horarios as $h) {
                    $duplicate = Horario::where([
                        'grupo_id'    => $match->id,
                        'dia'         => $h->dia,
                        'hora_inicio' => $h->hora_inicio
                    ])->exists();

                    if (!$duplicate) {
                        $h->update(['grupo_id' => $match->id]);
                    } else {
                        $h->delete();
                        $deletedSchedules++;
                    }
                }

                // Move Seguimientos
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

                // Delete the redundant REGULAR group
                $reg->forceDelete();
                $mergedCount++;
                echo "  MERGED group id={$reg->id} → target id={$match->id}\n";

            } else {
                // No match → try to rename to TEORICO
                // But first verify no TEORICO already exists with same unique key
                $conflicto = Grupo::where([
                    'gestion'       => $reg->gestion,
                    'asignatura_id' => $reg->asignatura_id,
                    'carrera_id'    => $reg->carrera_id,
                    'nombre'        => $reg->nombre,
                    'tipo'          => 'TEORICO',
                    'sede_id'       => $reg->sede_id,
                ])
                ->where('id', '<>', $reg->id)
                ->first();

                if ($conflicto) {
                    // Conflict would occur: merge into conflicto instead
                    $horarios = Horario::where('grupo_id', $reg->id)->get();
                    foreach ($horarios as $h) {
                        $duplicate = Horario::where([
                            'grupo_id'    => $conflicto->id,
                            'dia'         => $h->dia,
                            'hora_inicio' => $h->hora_inicio
                        ])->exists();
                        if (!$duplicate) {
                            $h->update(['grupo_id' => $conflicto->id]);
                        } else {
                            $h->delete();
                            $deletedSchedules++;
                        }
                    }
                    $reg->forceDelete();
                    $mergedCount++;
                    echo "  CONFLICT-MERGE group id={$reg->id} → target id={$conflicto->id}\n";
                } else {
                    // Safe to rename
                    $reg->update(['tipo' => 'TEORICO']);
                    $renamedCount++;
                }
            }
        });
    } catch (\Exception $e) {
        $errorCount++;
        echo "  ERROR for group id={$reg->id}: " . $e->getMessage() . "\n";
    }
}

echo "\nCleanup finished:\n";
echo "- Groups merged and deleted: $mergedCount\n";
echo "- Groups renamed to TEORICO: $renamedCount\n";
echo "- Duplicate schedules removed: $deletedSchedules\n";
echo "- Errors (skipped): $errorCount\n";
