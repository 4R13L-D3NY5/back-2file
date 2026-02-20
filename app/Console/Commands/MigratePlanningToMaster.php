<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Cronograma;
use App\Models\Seguimiento;

class MigratePlanningToMaster extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'academic:migrate-planning {--dry-run : Only show what would be changed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrates existing group-based planning to the new asignatura-based Master format';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting planning migration to Master format...');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE: No changes will be saved to the database.');
        }

        // 1. Identify Asignaturas that have group-based planning but NO master planning
        $asignaturasToMigrate = DB::table('cronogramas')
            ->whereNotNull('grupo_id')
            ->whereNotIn('asignatura_id', function($query) {
                $query->select('asignatura_id')
                      ->from('cronogramas')
                      ->whereNull('grupo_id');
            })
            ->distinct()
            ->pluck('asignatura_id');

        $this->info('Found ' . $asignaturasToMigrate->count() . ' asignaturas needing a Master Planning.');

        foreach ($asignaturasToMigrate as $asignaturaId) {
            $this->line("Processing Asignatura ID: $asignaturaId");

            // Pick the first group that has planning for this asignatura
            $sourceGroup = DB::table('cronogramas')
                ->where('asignatura_id', $asignaturaId)
                ->whereNotNull('grupo_id')
                ->orderBy('numero_sesion')
                ->value('grupo_id');

            if (!$sourceGroup) continue;

            $this->info("  -> Converting Group $sourceGroup to Master for Asignatura $asignaturaId");

            if (!$dryRun) {
                // Convert all sessions of this group to Master
                DB::table('cronogramas')
                    ->where('asignatura_id', $asignaturaId)
                    ->where('grupo_id', $sourceGroup)
                    ->update(['grupo_id' => null]);
                
                $this->info("  -> Success: Group $sourceGroup is now the Master.");
            }
        }

        // 2. Handle compliance (avance) migration
        // We look for any cronograma record (master or group-based) that has tracking data
        // and move it to the 'seguimientos' table if it doesn't already exist.
        
        $this->info('Checking for legacy compliance data (cumplido, observaciones)...');

        $legacyRecords = DB::table('cronogramas')
            ->where(function($q) {
                $q->where('cumplido', 1)
                  ->orWhereNotNull('observaciones')
                  ->orWhereNotNull('pedagogico');
            })
            ->get();

        $this->info('Found ' . $legacyRecords->count() . ' records with legacy tracking data.');

        foreach ($legacyRecords as $record) {
            // We need a grupo_id to create a seguimiento. 
            // If the record itself has a grupo_id, we use it.
            // If it's already a master (grupo_id is null), we can't easily know WHICH group it was for, 
            // unless we assume it was for ALL groups or just leave it. 
            // But since the new logic requires a specific group for follow-up, 
            // we only migrate if we have a grupo_id.
            
            if (!$record->grupo_id) {
                $this->warn("  -> Skipping session #{$record->numero_sesion} of Asignatura {$record->asignatura_id}: Master record with tracking but no group context.");
                continue;
            }

            // Check if seguimiento already exists
            $exists = DB::table('seguimientos')
                ->where('cronograma_id', $record->id)
                ->where('grupo_id', $record->grupo_id)
                ->exists();

            if (!$exists) {
                $this->line("  -> Creating seguimiento for Asignatura {$record->asignatura_id}, Group {$record->grupo_id}, Session #{$record->numero_sesion}");
                
                if (!$dryRun) {
                    // Try to extract user_id from the group (if available) or use generic 1
                    $docenteId = DB::table('grupos')->where('id', $record->grupo_id)->value('docente_id');
                    $userId = 1; // Default
                    if ($docenteId) {
                        $userId = DB::table('docentes')->where('id', $docenteId)->value('user_id') ?: 1;
                    }

                    DB::table('seguimientos')->insert([
                        'cronograma_id' => $record->id,
                        'grupo_id' => $record->grupo_id,
                        'user_id' => $userId,
                        'fecha' => $record->fecha ?: now(),
                        'cumplido' => $record->cumplido ?: 1,
                        'tema_cumplido' => $record->cumplido ?: 1,
                        'estado_cumplimiento' => 'TOTAL', // Legacy was always TOTAL or nothing
                        'observaciones' => $record->observaciones,
                        'pedagogico' => $record->pedagogico ?: '{}',
                        'evidencias' => '{}', // No legacy evidences
                        'integracion_transversal' => '{}',
                        'created_at' => $record->updated_at ?: now(),
                        'updated_at' => $record->updated_at ?: now(),
                    ]);
                }
            }
        }

        // 3. Cleanup: Any remaining group-based planning?
        // Since we now use a Master, we don't need redundant group-based planning rows 
        // UNLESS they have different content. But the new system doesn't support group-specific planning.
        // We'll keep them for now but they won't be used by the new 'index' method.
        // Better: encourage users to delete them or just leave them dormant.

        $this->info('Migration completed successfully!');
    }
}
