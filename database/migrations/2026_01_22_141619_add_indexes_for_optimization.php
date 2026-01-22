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
        Schema::table('asignatura_carrera', function (Blueprint $table) {
            // Composite index for common filtering (Sede + Carrera)
            $table->index(['sede_id', 'carrera_id'], 'idx_ac_sede_carrera');
            // Index for semester filtering
            $table->index('semestre', 'idx_ac_semestre');
        });

        Schema::table('grupos', function (Blueprint $table) {
            // Indexes for foreign keys and common filters
            $table->index('docente_id', 'idx_grupos_docente');
            $table->index('asignatura_id', 'idx_grupos_asignatura');
            $table->index('gestion', 'idx_grupos_gestion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asignatura_carrera', function (Blueprint $table) {
            $table->dropIndex('idx_ac_sede_carrera');
            $table->dropIndex('idx_ac_semestre');
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->dropIndex('idx_grupos_docente');
            $table->dropIndex('idx_grupos_asignatura');
            $table->dropIndex('idx_grupos_gestion');
        });
    }
};
