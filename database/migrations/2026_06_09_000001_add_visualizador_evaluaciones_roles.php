<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('roles')->updateOrInsert(
            ['codigo' => 'VISUALIZADOR_EVALUACIONES_GLOBAL'],
            [
                'nombre' => 'VISUALIZADOR DE EVALUACIONES GLOBAL',
                'descripcion' => 'Consulta de gestion de evaluaciones a nivel global sin acceso a documentos ni modificaciones',
                'color' => '#2563eb',
                'icono' => 'visibility',
                'activo' => true,
                'permisos' => json_encode(['evaluaciones_consulta']),
                'orden' => 10,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        DB::table('roles')->updateOrInsert(
            ['codigo' => 'VISUALIZADOR_EVALUACIONES_SEDE'],
            [
                'nombre' => 'VISUALIZADOR DE EVALUACIONES POR SEDE',
                'descripcion' => 'Consulta de gestion de evaluaciones para sedes asignadas sin acceso a documentos ni modificaciones',
                'color' => '#0f766e',
                'icono' => 'visibility',
                'activo' => true,
                'permisos' => json_encode(['evaluaciones_consulta_sede']),
                'orden' => 11,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('codigo', [
                'VISUALIZADOR_EVALUACIONES_GLOBAL',
                'VISUALIZADOR_EVALUACIONES_SEDE',
            ])
            ->delete();
    }
};
