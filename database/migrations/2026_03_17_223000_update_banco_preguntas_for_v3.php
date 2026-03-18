<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('banco_preguntas', function (Blueprint $table) {
            // Hacer logro_esperado_id opcional
            $table->foreignId('logro_esperado_id')->nullable()->change();
            
            // Añadir relación con asignatura si no existe
            if (!Schema::hasColumn('banco_preguntas', 'asignatura_id')) {
                $table->foreignId('asignatura_id')->nullable()->after('id')->constrained('asignaturas')->cascadeOnDelete();
            }
            
            // Asegurar que el parcial sea almacenable (si no existe una columna específica, 
            // aunque el Excel V3 lo trae, podemos guardarlo en un campo nuevo o meta)
            if (!Schema::hasColumn('banco_preguntas', 'parcial')) {
                $table->string('parcial')->nullable()->after('dificultad');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banco_preguntas', function (Blueprint $table) {
            $table->foreignId('logro_esperado_id')->nullable(false)->change();
            $table->dropForeign(['asignatura_id']);
            $table->dropColumn(['asignatura_id', 'parcial']);
        });
    }
};
