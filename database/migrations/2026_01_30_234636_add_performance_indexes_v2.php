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
        if (Schema::hasTable('cronogramas')) {
            Schema::table('cronogramas', function (Blueprint $table) {
                if (Schema::hasColumn('cronogramas', 'grupo_id')) {
                    $table->index(['grupo_id', 'fecha'], 'idx_crono_grp_fecha');
                }
            });
        }

        // 2. Asistencias: Index for reports
        if (Schema::hasTable('asistencias')) {
            Schema::table('asistencias', function (Blueprint $table) {
                $table->index(['cronograma_id', 'asistio'], 'idx_asist_crono_asistio');
            });
        }

        // 3. Planificacion Personal: Status check index
        if (Schema::hasTable('planificaciones_personales')) {
            Schema::table('planificaciones_personales', function (Blueprint $table) {
                $table->index(['user_id', 'tema_id'], 'idx_pp_u_t');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('cronogramas')) {
            Schema::table('cronogramas', function (Blueprint $table) {
                $table->dropIndex('idx_crono_grp_fecha');
            });
        }

        if (Schema::hasTable('asistencias')) {
            Schema::table('asistencias', function (Blueprint $table) {
                $table->dropIndex('idx_asist_crono_asistio');
            });
        }

        if (Schema::hasTable('planificaciones_personales')) {
            Schema::table('planificaciones_personales', function (Blueprint $table) {
                $table->dropIndex('idx_pp_u_t');
            });
        }
    }
};
