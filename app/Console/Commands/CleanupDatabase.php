<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

class CleanupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-database {--force : Confirmar la eliminación de datos}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Vacía la información académica dejando únicamente a los usuarios administrativos y prepara la base para una nueva sincronización.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->option('force')) {
            $this->warn('¡ATENCIÓN! Esta operación es DESTRUCTIVA.');
            $this->warn('Se eliminarán todos los docentes, asignaturas, grupos y contenido pedagógico.');
            if (!$this->confirm('¿Estás seguro de que deseas continuar?')) {
                return;
            }
        }

        $this->info('Iniciando limpieza de base de datos...');

        $tablesToTruncate = [
            // Contenido Pedagógico
            'unidades',
            'temas',
            'seguimientos',
            'cronogramas',
            'planificaciones_personales',
            'logros_esperados',
            'indicadores',
            'informe_semanals',
            'seguimiento_semanal',
            'asistencias',
            
            // Evaluación
            'banco_preguntas',
            'evaluaciones',
            'evaluacion_configuraciones',
            'evaluacion_tiempos',
            'examenes_generados',
            'generaciones_manuales',
            
            // Estructura Académica (Estos se recuperarán en la sincronización)
            'matriculas',
            'estudiantes',
            'docentes',
            'grupos',
            'asignaturas',
            'horarios',
            'aulas',
            
            // Tablas Pivot y Relaciones Académicas
            // NOTA: Se preservan 'director_carrera' y 'carrera_sede' para no romper la administración
            'asignatura_carrera',
            'bibliografias',
            'tema_bibliografias',
            'cronograma_tema',
            'estrategias_didacticas',
            'secuencia_didacticas',
            'secuencia_temas',
            'rol_examenes',
            'planning_cache',
            'sync_logs'
        ];

        Schema::disableForeignKeyConstraints();

        foreach ($tablesToTruncate as $table) {
            if (Schema::hasTable($table)) {
                $this->info("Vaciando tabla: {$table}");
                DB::table($table)->truncate();
            } else {
                $this->warn("La tabla {$table} no existe, omitiendo...");
            }
        }

        // Eliminar usuarios con rol de DOCENTE (ID: 6)
        $this->info('Eliminando usuarios con rol de DOCENTE (ID: 6)...');
        $deletedUsers = User::where('rol_id', 6)->delete();
        $this->info("Usuarios eliminados: {$deletedUsers}");

        Schema::enableForeignKeyConstraints();

        $this->info('Limpieza completada con éxito.');
        $this->info('La base de datos está lista para una nueva sincronización.');
    }
}
