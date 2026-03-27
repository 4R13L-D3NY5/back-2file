<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $roles = [
            [
                'id' => 1,
                'nombre' => 'SUPER ADMIN',
                'codigo' => 'SUPER_ADMIN',
                'descripcion' => 'Acceso completo al sistema con todos los permisos administrativos',
                'color' => '#dc2626',
                'icono' => 'admin_panel_settings',
                'activo' => true,
                'permisos' => json_encode(['*']),
                'orden' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'nombre' => 'ADMIN',
                'codigo' => 'ADMIN',
                'descripcion' => 'Administrador del sistema con permisos de gestión general',
                'color' => '#ea580c',
                'icono' => 'manage_accounts',
                'activo' => true,
                'permisos' => json_encode(['usuarios', 'roles', 'configuracion']),
                'orden' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'nombre' => 'VICERRECTORADO NACIONAL',
                'codigo' => 'VICERRECTORADO_NACIONAL',
                'descripcion' => 'Autoridad académica superior con visión global institucional',
                'color' => '#7c3aed',
                'icono' => 'school',
                'activo' => true,
                'permisos' => json_encode(['reportes', 'estadisticas', 'aprobaciones']),
                'orden' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 4,
                'nombre' => 'DIRECCIÓN ACADÉMICA',
                'codigo' => 'DIRECCION_ACADEMICA',
                'descripcion' => 'Gestión y supervisión de procesos académicos institucionales',
                'color' => '#2563eb',
                'icono' => 'account_balance',
                'activo' => true,
                'permisos' => json_encode(['planificacion', 'seguimiento', 'reportes']),
                'orden' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 5,
                'nombre' => 'DIRECTOR DE CARRERA',
                'codigo' => 'DIRECTOR_CARRERA',
                'descripcion' => 'Responsable de la gestión académica de una carrera específica',
                'color' => '#0891b2',
                'icono' => 'engineering',
                'activo' => true,
                'permisos' => json_encode(['docentes', 'estudiantes', 'horarios', 'materias']),
                'orden' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 6,
                'nombre' => 'DOCENTE',
                'codigo' => 'DOCENTE',
                'descripcion' => 'Personal académico encargado de la enseñanza',
                'color' => '#059669',
                'icono' => 'person',
                'activo' => true,
                'permisos' => json_encode(['notas', 'asistencia', 'materiales']),
                'orden' => 6,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 7,
                'nombre' => 'EVALUACIONES',
                'codigo' => 'EVALUACIONES',
                'descripcion' => 'Gestión y supervisión del sistema de evaluaciones académicas',
                'color' => '#ca8a04',
                'icono' => 'quiz',
                'activo' => true,
                'permisos' => json_encode(['evaluaciones', 'examenes', 'resultados']),
                'orden' => 7,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 8,
                'nombre' => 'VICERRECTORADO',
                'codigo' => 'VICERRECTORADO',
                'descripcion' => 'Autoridad académica a nivel sede con visión local',
                'color' => '#8b5cf6',
                'icono' => 'location_city',
                'activo' => true,
                'permisos' => json_encode(['reportes', 'estadisticas', 'aprobaciones']),
                'orden' => 8,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 9,
                'nombre' => 'RESPONSABLE DE EVALUACIONES',
                'codigo' => 'RESPONSABLE_EVALUACIONES',
                'descripcion' => 'Gestión nacional de evaluaciones y administración del sistema de exámenes',
                'color' => '#be185d',
                'icono' => 'admin_panel_settings',
                'activo' => true,
                'permisos' => json_encode(['evaluaciones', 'examenes', 'administración']),
                'orden' => 9,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('roles')->insert($roles);
    }
}
