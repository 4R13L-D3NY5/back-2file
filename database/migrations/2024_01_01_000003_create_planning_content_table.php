<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BIBLIOGRAFIAS
        Schema::create('bibliografias', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->string('autor')->nullable();
            $table->string('anio')->nullable();
            $table->string('tipo')->nullable(); // Basica/Complementaria
            $table->foreignId('asignatura_id')->constrained('asignaturas')->cascadeOnDelete();
            $table->timestamps();
        });

        // UNIDADES
        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->string('numero'); // Ej: "1", "I"
            $table->string('titulo');
            $table->text('objetivo')->nullable();
            $table->text('contenido_minimo')->nullable();
            $table->foreignId('asignatura_id')->constrained('asignaturas')->cascadeOnDelete();
            $table->timestamps();
        });

        // TEMAS
        Schema::create('temas', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            
            // Contenidos (Saberes) - JSON o Texto rico
            $table->text('contenido_conceptual')->nullable();
            $table->text('contenido_procedimental')->nullable();
            $table->text('contenido_actitudinal')->nullable();

            $table->integer('horas_practicas')->default(0);
            $table->integer('horas_teoricas')->default(0);
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->timestamps();
        });

        // ESTRATEGIAS (Nivel Planificación - Tema)
        Schema::create('estrategias_temas', function (Blueprint $table) {
            $table->id();
            $table->string('tipo'); // Metodologica (Docente) / Aprendizaje (Estudiante)
            $table->text('descripcion');
            $table->json('recursos')->nullable(); // Lista de recursos
            $table->foreignId('tema_id')->constrained('temas')->cascadeOnDelete();
            $table->timestamps();
        });

        // EVALUACIONES (Nivel Planificación - Tema)
        Schema::create('evaluaciones_temas', function (Blueprint $table) {
            $table->id();
            $table->string('categoria'); // Formativa / Sumativa
            $table->string('tipo'); // Quiz, Participacion, Examen...
            $table->string('instrumento')->nullable(); // Rubrica, Lista de cotejo...
            $table->string('evidencia')->nullable(); // Archivo, Registro...
            $table->foreignId('tema_id')->constrained('temas')->cascadeOnDelete();
            $table->timestamps();
        });

        // SECUENCIA DIDACTICA (Nivel Planificación - Tema)
        Schema::create('secuencias_temas', function (Blueprint $table) {
            $table->id();
            $table->string('momento'); // Inicio / Desarrollo / Cierre
            $table->text('descripcion');
            $table->integer('duracion_minutos')->default(0);
            $table->foreignId('tema_id')->constrained('temas')->cascadeOnDelete();
            $table->timestamps();
        });

        // LOGROS ESPERADOS
        Schema::create('logros_esperados', function (Blueprint $table) {
            $table->id();
            $table->string('descripcion');
            $table->string('tipo_logro')->nullable(); // Saber, Hacer, Ser
            $table->foreignId('tema_id')->constrained('temas')->cascadeOnDelete();
            $table->timestamps();
        });

        // INDICADORES (Hijos de Logros)
        Schema::create('indicadores', function (Blueprint $table) {
            $table->id();
            $table->string('descripcion');
            $table->foreignId('logro_esperado_id')->constrained('logros_esperados')->cascadeOnDelete();
            $table->timestamps();
        });

        // BANCO DE PREGUNTAS
        Schema::create('banco_preguntas', function (Blueprint $table) {
            $table->id();
            $table->text('enunciado');
            $table->string('tipo'); // SELECCION_UNICA, SELECCION_MULTIPLE, FALSO_VERDADERO
            $table->json('opciones')->nullable(); // Estructura: [{id: 1, text: "A"}, {id: 2, text: "B"}]
            $table->json('respuesta_correcta'); // Puede ser un ID o array de IDs
            $table->string('dificultad')->default('MEDIA'); // BAJA, MEDIA, ALTA
            $table->integer('peso')->default(1);
            
            $table->foreignId('logro_esperado_id')->constrained('logros_esperados')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logros_esperados');
        Schema::dropIfExists('temas');
        Schema::dropIfExists('unidades');
        Schema::dropIfExists('bibliografias');
    }
};
