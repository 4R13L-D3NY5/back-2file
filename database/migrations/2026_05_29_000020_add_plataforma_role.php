<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            ['codigo' => 'PLATAFORMA'],
            [
                'nombre' => 'PLATAFORMA',
                'descripcion' => 'Acceso operativo al plan de estudios y exportacion de patrones',
                'color' => '#0f766e',
                'icono' => 'layers',
                'activo' => true,
                'permisos' => json_encode(['plan_estudios', 'exportar_patron']),
                'orden' => 10,
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('roles')->where('codigo', 'PLATAFORMA')->delete();
    }
};
