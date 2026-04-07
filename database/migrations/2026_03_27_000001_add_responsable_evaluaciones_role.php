<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $roleExists = DB::table('roles')->where('codigo', 'RESPONSABLE_EVALUACIONES')->exists();

        if (!$roleExists) {
            DB::table('roles')->insert([
                'nombre' => 'RESPONSABLE DE EVALUACIONES',
                'codigo' => 'RESPONSABLE_EVALUACIONES',
                'descripcion' => 'Gestión nacional de evaluaciones y administración del sistema de exámenes',
                'color' => '#be185d',
                'icono' => 'admin_panel_settings',
                'activo' => true,
                'permisos' => json_encode(['evaluaciones', 'examenes', 'administración']),
                'orden' => 9,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('roles')->where('codigo', 'RESPONSABLE_EVALUACIONES')->delete();
    }
};
