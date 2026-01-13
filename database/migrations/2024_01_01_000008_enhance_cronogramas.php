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
        // 1. Configuración de Fechas en Asignatura
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->date('fecha_inicio_clases')->nullable()->after('semestre');
            $table->date('fecha_fin_clases')->nullable()->after('fecha_inicio_clases');
            $table->string('gestion_academica')->nullable()->after('fecha_fin_clases'); // Ej: 2026-I
        });

        // 2. Enriquecer Cronogramas (Sesiones de Planificación)
        Schema::table('cronogramas', function (Blueprint $table) {
            // Contexto
            $table->string('periodo_examen')->nullable(); // 1er Parcial, Final...
            $table->integer('semana_academica')->nullable(); // 1, 2, ... 20
            
            // Contenidos (Snapshot del tema o especifico para la sesión)
            $table->text('contenido_conceptual')->nullable();
            $table->text('contenido_procedimental')->nullable();
            $table->text('contenido_actitudinal')->nullable();
            
            // Evaluación
            $table->text('criterios_desempeno')->nullable();
            $table->text('instrumentos_evaluacion')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cronogramas', function (Blueprint $table) {
            $table->dropColumn([
                'periodo_examen', 'semana_academica',
                'contenido_conceptual', 'contenido_procedimental', 'contenido_actitudinal',
                'criterios_desempeno', 'instrumentos_evaluacion'
            ]);
        });

        Schema::table('asignaturas', function (Blueprint $table) {
            $table->dropColumn(['fecha_inicio_clases', 'fecha_fin_clases', 'gestion_academica']);
        });
    }
};
