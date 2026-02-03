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
            try {
                Schema::table('cronogramas', function (Blueprint $table) {
                    if (Schema::hasColumn('cronogramas', 'grupo_id')) {
                        $table->index(['grupo_id', 'fecha'], 'idx_crono_grp_fecha');
                    }
                });
            } catch (\Exception $e) {
            }
        }

        // 2. Asistencias: Index for reports
        if (Schema::hasTable('asistencias')) {
            try {
                Schema::table('asistencias', function (Blueprint $table) {
                    if (Schema::hasColumn('asistencias', 'cronograma_id')) {
                        $table->index(['cronograma_id', 'asistio'], 'idx_asist_crono_asistio');
                    }
                });
            } catch (\Exception $e) {
            }
        }

        // 3. Planificaciones Personales: Status check index
        if (Schema::hasTable('planificaciones_personales')) {
            try {
                Schema::table('planificaciones_personales', function (Blueprint $table) {
                    if (Schema::hasColumn('planificaciones_personales', 'user_id')) {
                        $table->index(['user_id', 'tema_id'], 'idx_pp_u_t');
                    }
                });
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('cronogramas')) {
            try {
                Schema::table('cronogramas', function (Blueprint $table) {
                    $table->dropIndex('idx_crono_grp_fecha');
                });
            } catch (\Exception $e) {
            }
        }

        if (Schema::hasTable('asistencias')) {
            try {
                Schema::table('asistencias', function (Blueprint $table) {
                    $table->dropIndex('idx_asist_crono_asistio');
                });
            } catch (\Exception $e) {
            }
        }

        if (Schema::hasTable('planificaciones_personales')) {
            try {
                Schema::table('planificaciones_personales', function (Blueprint $table) {
                    $table->dropIndex('idx_pp_u_t');
                });
            } catch (\Exception $e) {
            }
        }
    }
};
