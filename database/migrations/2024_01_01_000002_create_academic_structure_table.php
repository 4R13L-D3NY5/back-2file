<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CARRERAS
        Schema::create('carreras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('facultad')->nullable();
            $table->string('sede')->nullable();
            $table->foreignId('director_id')->nullable()->constrained('directors')->nullOnDelete();
            $table->timestamps();
        });

        // ASIGNATURAS (Tabla Central)
        Schema::create('asignaturas', function (Blueprint $table) {
            $table->id();
            // Datos Espejo (Vienen de API)
            $table->string('codigo')->index();
            $table->string('nombre');
            $table->string('semestre')->nullable();
            $table->integer('creditos')->default(0);
            
            // Datos Extendidos (Locales - SIDOPA)
            $table->string('area_desempenio')->nullable();
            $table->string('tipo_curso')->nullable();
            $table->string('modalidad')->nullable();
            
            // Cargas Horarias
            $table->integer('carga_horaria_total')->default(0);
            $table->integer('horas_teoricas')->default(0);
            $table->integer('horas_practicas')->default(0);
            $table->integer('sesiones_semanales_teoricas')->default(0);
            $table->integer('sesiones_semanales_practicas')->default(0);
            
            // Contenido Rico (Documento Base)
            $table->text('requisitos')->nullable();
            $table->text('justificacion')->nullable();
            $table->text('proposito_general')->nullable();
            $table->text('metodologia_general')->nullable();
            $table->text('sistema_evaluacion')->nullable();
            
            // Relaciones
            $table->foreignId('carrera_id')->nullable()->constrained('carreras')->nullOnDelete();
            $table->foreignId('docente_id')->nullable()->constrained('docentes')->nullOnDelete();
            
            $table->timestamps();
        });

        // MATRICULAS
        Schema::create('matriculas', function (Blueprint $table) {
            $table->id();
            $table->string('gestion'); // Ej: 1-2024
            $table->foreignId('estudiante_id')->constrained('estudiantes')->cascadeOnDelete();
            $table->foreignId('asignatura_id')->constrained('asignaturas')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matriculas');
        Schema::dropIfExists('asignaturas');
        Schema::dropIfExists('carreras');
    }
};
