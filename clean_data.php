<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

echo "Iniciando Limpieza de Datos Académicos Falsos...\n";

// Deshabilitar FKs
Schema::disableForeignKeyConstraints();

try {
    // Tablas a Vaciar (Academic Structure & Data)
    $tablesToTruncate = [
        'asignaturas', 
        'asignatura_carrera', // Pivot
        'carreras', 
        'carrera_sede', // Pivot
        'grupos',
        'cronogramas',
        'plan_clases', // If separate
        'unidades',
        'temas',
        'horarios',
        'asistencias',
        'evaluaciones',
        'matriculas',
        'planificacion_personal',
        'rol_examenes',
        'seguimiento_semanal',
        'banco_preguntas',
        'bibliografias',
        'grupos_externo' // Cache
    ];

    foreach ($tablesToTruncate as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->truncate();
            echo " - Tabla '$table' vaciada.\n";
        } else {
            echo " - Tabla '$table' no existe (saltando).\n";
        }
    }

    // Docentes: Cuidado especial.
    // Si la tabla 'docentes' almacena info extra y no es users, debemos ver.
    // En UserSeeder se ve 'rol_id' => 6 // DOCENTE.
    // DocenteController usa 'Docente' model.
    // Si usas 'users' como base, borrar 'docentes' (tabla separada) es seguro si se resincroniza.
    if (Schema::hasTable('docentes')) {
        DB::table('docentes')->truncate();
        echo " - Tabla 'docentes' vaciada (Se regenerará con sync).\n";
    }

    // Sedes: Preservamos si es tabla maestra, pero si tiene basura...
    // El usuario dijo "menos usuarios". Sedes es estructura fija.
    // Mejor NO borrar Sedes para evitar perder IDs 1, 6, 9...
    echo " - Tabla 'sedes' preservada.\n";
    
    // Users y Roles:
    echo " - Tabla 'users' preservada.\n";
    echo " - Tabla 'roles' preservada.\n";
    echo " - Tabla 'permissions' preservada.\n";

    echo "\nLimpieza Completada Exitosamente.\n";

} catch (\Exception $e) {
    echo "\nERROR CRITICO: " . $e->getMessage() . "\n";
} finally {
    Schema::enableForeignKeyConstraints();
}
