<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Asignatura;
use Illuminate\Support\Facades\DB;

class DeduplicateAsignaturas extends Command
{
    protected $signature = 'db:deduplicate-asignaturas';
    protected $description = 'Merge and delete duplicate asignaturas based on codigo and carrera_id';

    public function handle()
    {
        $this->info("Starting Deduplication...");

        // 1. Find duplicates
        $duplicates = DB::table('asignaturas')
            ->select('codigo', 'carrera_id', DB::raw('count(*) as count'))
            ->groupBy('codigo', 'carrera_id')
            ->having('count', '>', 1)
            ->get();

        $this->info("Found " . $duplicates->count() . " sets of duplicates.");

        foreach ($duplicates as $dup) {
            $this->info("Processing Code: {$dup->codigo} | Carrera ID: {$dup->carrera_id}");

            // Get all records for this group
            $records = Asignatura::where('codigo', $dup->codigo)
                ->where('carrera_id', $dup->carrera_id)
                ->orderBy('id') // Keep the oldest ID as master? Or one with most data?
                ->get();

            // Strategy: Keep strict oldest ID as Master to avoid breaking FKs somewhere else if possible.
            // But we should check if one is "Local" vs "API".

            $master = $records->first();
            $slaves = $records->slice(1);

            $this->info(" -> Master ID: {$master->id} | Slaves: " . $slaves->pluck('id')->implode(', '));

            foreach ($slaves as $slave) {
                // Merge Data

                // 1. Docentes Pivot
                // Get slave relations
                $slaveDocentes = $slave->docentes;
                foreach ($slaveDocentes as $docente) {
                    // Check if master already has this docente/grupo combo?
                    // Better verify by IDs.

                    // Simple approach: detach from slave, attach to master if not exists.
                    // Or Update generic ID in pivot table.

                    try {
                        // Update FK directly in pivot to point to Master
                        // Warn: This might cause duplicate key errors if master ALREADY has this Docente+Group.
                        // So we try update, catch error.

                        $exists = DB::table('asignatura_docente')
                            ->where('asignatura_id', $master->id)
                            ->where('docente_id', $docente->id)
                            ->where('grupo', $docente->pivot->grupo)
                            ->exists();

                        if (!$exists) {
                            DB::table('asignatura_docente')
                                ->where('asignatura_id', $slave->id)
                                ->where('docente_id', $docente->id)
                                ->where('grupo', $docente->pivot->grupo)
                                ->update(['asignatura_id' => $master->id]);
                        } else {
                            // Master already has it. Just delete from slave.
                            DB::table('asignatura_docente')
                                ->where('asignatura_id', $slave->id)
                                ->where('docente_id', $docente->id)
                                ->where('grupo', $docente->pivot->grupo)
                                ->delete();
                        }
                    } catch (\Exception $e) {
                        $this->warn("Error merging docentes: " . $e->getMessage());
                    }
                }

                // 2. Unidades (HasMany)
                foreach ($slave->unidades as $unidad) {
                    $unidad->asignatura_id = $master->id;
                    $unidad->save();
                }

                // 3. Bibliografias (HasMany)
                foreach ($slave->bibliografias as $bib) {
                    $bib->asignatura_id = $master->id;
                    $bib->save();
                }

                // 4. Delete Slave
                try {
                    $slave->delete();
                    $this->info("    Deleted Slave ID: {$slave->id}");
                } catch (\Exception $e) {
                    $this->error("    Failed to delete {$slave->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Deduplication Complete.");
    }
}
