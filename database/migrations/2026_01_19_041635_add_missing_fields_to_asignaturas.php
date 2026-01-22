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
        Schema::table('asignaturas', function (Blueprint $table) {
            if (!Schema::hasColumn('asignaturas', 'competencia_global_especifica')) {
                $table->text('competencia_global_especifica')->nullable();
            }
            if (!Schema::hasColumn('asignaturas', 'reglamento_normativa')) {
                $table->text('reglamento_normativa')->nullable();
            }
            if (!Schema::hasColumn('asignaturas', 'organizacion_calendario')) {
                $table->text('organizacion_calendario')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asignaturas', function (Blueprint $table) {
            if (Schema::hasColumn('asignaturas', 'competencia_global_especifica')) {
                $table->dropColumn('competencia_global_especifica');
            }
            if (Schema::hasColumn('asignaturas', 'reglamento_normativa')) {
                $table->dropColumn('reglamento_normativa');
            }
            if (Schema::hasColumn('asignaturas', 'organizacion_calendario')) {
                $table->dropColumn('organizacion_calendario');
            }
        });
    }
};
