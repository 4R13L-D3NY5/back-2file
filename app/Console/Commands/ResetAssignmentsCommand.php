<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use App\Models\Grupo;

class ResetAssignmentsCommand extends Command
{
    protected $signature = 'academic:reset-assignments {gestion=1-2026} {--force : Force execution without confirmation}';
    protected $description = 'Wipes all teacher assignments for a gestion and re-runs synchronization to clean bad data.';

    public function handle()
    {
        $gestion = $this->argument('gestion');

        $this->alert("ATENCIÓN: MODO DE CORRECCIÓN MASIVA");
        $this->warn("Esta acción va a:");
        $this->line("1. ELIMINAR la asignación de docentes de TODOS los grupos de la gestión $gestion.");
        $this->line("2. Ejecutar la sincronización desde cero para volver a asignar los docentes correctos.");
        $this->line("Esto corregirá problemas de 'materias fantasma' o asignaciones de la sincronización anterior.");

        if (!$this->option('force') && !$this->confirm('¿Está seguro de continuar?')) {
            $this->info("Operación cancelada.");
            return;
        }

        // Paso 1: Limpiar asignaciones
        $this->info("Paso 1: Limpiando asignaciones de docentes para $gestion...");
        $affected = DB::table('grupos')
            ->where('gestion', $gestion)
            ->whereNotNull('docente_id')
            ->update(['docente_id' => null]);

        $this->info("✓ Se desasignaron docentes de $affected grupos.");

        // Paso 2: Re-sincronizar
        $this->info("Paso 2: Ejecutando sincronización desde API...");

        // Ejecutar academic:sync
        $exitCode = Artisan::call('academic:sync', [
            'gestion' => $gestion,
            '--all' => true
        ], $this->getOutput());

        if ($exitCode === 0) {
            $this->info("\n✓ PROCESO COMPLETADO EXITOSAMENTE.");
            $this->line("Ahora los docentes solo tienen las materias que figuran AL DÍA DE HOY en el sistema oficial.");
        } else {
            $this->error("\n❌ Hubo un error durante la sincronización.");
        }
    }
}
