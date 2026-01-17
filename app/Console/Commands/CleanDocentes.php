<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Docente;
use App\Models\User;

class CleanDocentes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:clean-docentes {--force : Forzar eliminación sin confirmar}';

    public function handle()
    {
        if (!$this->option('force') && !$this->confirm('¿ESTÁS SEGURO? Esto borrará TODOS los docentes y usuarios con rol DOCENTE (ID 6).')) {
            return;
        }

        $this->info("Iniciando limpieza de docentes...");

        // 1. Truncar tabla pivote (asignaciones)
        DB::table('asignatura_docente')->truncate();
        $this->info(" - Tabla 'asignatura_docente' truncada.");

        // 2. Eliminar Docentes (Esto debería borrar cascadas si están configuradas, pero lo hacemos explícito)
        // Disable foreign key checks temporarly if needed, or delete safely
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Docente::truncate();
        $this->info(" - Tabla 'docentes' truncada.");
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // 3. Eliminar Usuarios con Rol 6 (Docente)
        $countDocentes = User::where('rol_id', 6)->delete();
        $this->line(" - Eliminados $countDocentes usuarios con rol DOCENTE (ID 6).");

        // 4. Eliminar Usuarios Falsos (Rol 3 que sean 'docente.%')
        $countFakes = User::where('rol_id', 3)
            ->where('username', 'like', 'docente.%')
            ->delete();
        $this->line(" - Eliminados $countFakes usuarios falsos (Rol 3, username 'docente.%').");

        $this->info("Limpieza completada exitosamente.");
    }
}
