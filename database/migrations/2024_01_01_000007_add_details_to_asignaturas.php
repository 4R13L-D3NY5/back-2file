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
            $table->string('sigla')->nullable()->after('codigo');
            $table->integer('horas_laboratorio')->default(0)->after('horas_practicas');
            $table->text('contenido_minimo')->nullable()->after('metodologia_general');
            // 'descripcion' already exists? No, checking migration logic:
            // Migration 0002 has 'proposito_general', 'justificacion', 'metodologia_general', 'sistema_evaluacion', 'requisitos'.
            // Frontend 'descripcion' corresponds to 'proposito_general' or a new field?
            // Frontend has 'descripcion' AND 'objetivo_general'.
            // DB has 'proposito_general'. Let's add 'descripcion' to be safe and explicit.
            $table->text('descripcion')->nullable()->after('nombre');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->dropColumn(['sigla', 'horas_laboratorio', 'contenido_minimo', 'descripcion']);
        });
    }
};
