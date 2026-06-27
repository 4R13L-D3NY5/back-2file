<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            // Agregar 'asignatura' al enum 'modo' (MySQL requiere modificación directa)
            // Se hará con sentencia raw después de agregar columnas nuevas
            $table->string('codigo_asignatura', 50)->nullable()->after('carrera');
            $table->string('plan_estudios', 10)->nullable()->after('codigo_asignatura');
        });
        
        // Modificar el enum 'modo' para incluir 'asignatura' (solo MySQL)
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sync_logs MODIFY COLUMN modo ENUM('carrera', 'sede', 'materia', 'asignatura') DEFAULT 'carrera'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir el enum a su estado original (opcional, puede causar pérdida de datos si hay registros con 'asignatura')
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sync_logs MODIFY COLUMN modo ENUM('carrera', 'sede', 'materia') DEFAULT 'carrera'");
        }
        
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropColumn(['codigo_asignatura', 'plan_estudios']);
        });
    }
};
