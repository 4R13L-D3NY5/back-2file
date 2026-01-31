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
        // 1. Cronogramas: Index for date range filtering and group association
        Schema::table('cronogramas', function (Blueprint $table) {
            // Check if column exists just in case
            if (Schema::hasColumn('cronogramas', 'grupo_id')) {
                $table->index(['grupo_id', 'fecha'], 'idx_crono_grp_fecha');
            }
        });

        // 2. Asistencias: Index for reports
        Schema::table('asistencias', function (Blueprint $table) {
            $table->index(['cronograma_id', 'asistio'], 'idx_asist_crono_asistio');
        });

        // 3. Planificacion Personal: Status check index
        Schema::table('planificacion_personal', function (Blueprint $table) {
            $table->index(['user_id', 'tema_id'], 'idx_pp_u_t');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cronogramas', function (Blueprint $table) {
            $table->dropIndex('idx_crono_grp_fecha');
        });

        Schema::table('asistencias', function (Blueprint $table) {
            $table->dropIndex('idx_asist_crono_asistio');
        });

        Schema::table('planificacion_personal', function (Blueprint $table) {
            $table->dropIndex('idx_pp_u_t');
        });
    }
};
