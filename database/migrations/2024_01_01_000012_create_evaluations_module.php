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
        // 1. Configuración del Examen (Evaluación General)
        Schema::create('evaluaciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre'); // Ej: "Examen 1er Parcial - Anatomía"
            $table->string('parcial'); // 1er Parcial, 2do Parcial, Final...
            $table->date('fecha_examen');
            $table->time('hora_inicio');
            $table->integer('duracion_minutos');
            
            // Configuración
            $table->boolean('mezclar_preguntas')->default(true);
            $table->boolean('mezclar_opciones')->default(true);
            $table->enum('estado', ['PROGRAMADA', 'EN_CURSO', 'FINALIZADA'])->default('PROGRAMADA');
            
            $table->foreignId('asignatura_id')->constrained('asignaturas')->cascadeOnDelete();
            // $table->foreignId('docente_id')... (implícito en asignatura o añadido si se requiere)
            
            $table->timestamps();
        });

        // 2. Variantes Generadas (Para el Patrón: "TIPO A", "TIPO B")
        Schema::create('examenes_generados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluacion_id')->constrained('evaluaciones')->cascadeOnDelete();
            $table->string('tipo'); // A, B, C...
            $table->text('patron_respuestas_json')->nullable(); // Cache del patrón [{1:A}, {2:C}...]
            $table->timestamps();
        });

        // 3. Preguntas por Variante (Pivot)
        Schema::create('examen_preguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_generado_id')->constrained('examenes_generados')->cascadeOnDelete();
            $table->foreignId('banco_pregunta_id')->constrained('banco_preguntas')->cascadeOnDelete();
            $table->integer('orden'); // 1, 2, 3...
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('examen_preguntas');
        Schema::dropIfExists('examenes_generados');
        Schema::dropIfExists('evaluaciones');
    }
};
