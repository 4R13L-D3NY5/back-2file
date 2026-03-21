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
        // Carreras
        Schema::table('carreras', function (Blueprint $table) {
            if (!Schema::hasColumn('carreras', 'modificado_localmente')) {
                $table->boolean('modificado_localmente')->default(false)->after('codigo');
            }
        });

        // Sedes
        Schema::table('sedes', function (Blueprint $table) {
            if (!Schema::hasColumn('sedes', 'modificado_localmente')) {
                $table->boolean('modificado_localmente')->default(false)->after('activo');
            }
        });

        // Asignaturas
        Schema::table('asignaturas', function (Blueprint $table) {
            if (!Schema::hasColumn('asignaturas', 'modificado_localmente')) {
                $table->boolean('modificado_localmente')->default(false)->after('plan_estudios');
            }
        });

        // Grupos
        Schema::table('grupos', function (Blueprint $table) {
            if (!Schema::hasColumn('grupos', 'modificado_localmente')) {
                $table->boolean('modificado_localmente')->default(false)->after('plan_estudios');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carreras', function (Blueprint $table) {
            if (Schema::hasColumn('carreras', 'modificado_localmente')) {
                $table->dropColumn('modificado_localmente');
            }
        });

        Schema::table('sedes', function (Blueprint $table) {
            if (Schema::hasColumn('sedes', 'modificado_localmente')) {
                $table->dropColumn('modificado_localmente');
            }
        });

        Schema::table('asignaturas', function (Blueprint $table) {
            if (Schema::hasColumn('asignaturas', 'modificado_localmente')) {
                $table->dropColumn('modificado_localmente');
            }
        });

        Schema::table('grupos', function (Blueprint $table) {
            if (Schema::hasColumn('grupos', 'modificado_localmente')) {
                $table->dropColumn('modificado_localmente');
            }
        });
    }
};