<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use App\Models\Grupo;

class ResetAssignmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'academic:reset-assignments {gestion : La gestión a resetear (ej. 1-2026)} {--force : Forzar ejecución sin preguntar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resetea TODAS las asignaciones de docentes para una gestión y re-sincroniza desde cero.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $gestion = $this->argument('gestion');
        $force = $this->option('force');

        $this->alert("⚠️  ATENCIÓN: ESTA ACCIÓN ES DESTRUCTIVA PARA LAS ASIGNACIONES ⚠️");
        $this->warn("Se eliminará la asignación de docentes de TODOS los grupos de la gestión: {$gestion}");
        $this->warn("Los grupos, horarios y estudiantes SE MANTIENEN. Solo se 'desconecta' al profesor.");

        if (!$force && !$this->confirm('¿Estás seguro de continuar?')) {
            $this->info('Operación cancelada.');
            return 1;
        }

        $this->info("1. Iniciando limpieza de asignaciones para {$gestion}...");

        // Paso 1: Update masivo a NULL
        $affected = Grupo::where('gestion', $gestion)
            ->update(['docente_id' => null]);

        $this->info("✅ Se han desvinculado {$affected} grupos.");

        // Paso 2: Re-sincronización
        $this->info("2. Ejecutando Sincronización Maestra (academic:sync)...");

        // Llamamos al comando de sync existente
        $exitCode = Artisan::call('academic:sync', [
            'gestion' => $gestion
        ], $this->output);

        if ($exitCode === 0) {
            $this->info("🚀 ¡Proceso completado con éxito! Las asignaciones han sido regeneradas.");
            return 0;
        } else {
            $this->error("❌ Hubo un error durante la re-sincronización. Revisa los logs.");
            return 1;
        }
    }
}
