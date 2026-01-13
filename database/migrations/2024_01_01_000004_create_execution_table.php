<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // HORARIOS
        Schema::create('horarios', function (Blueprint $table) {
            $table->id();
            $table->string('dia'); // Lunes, Martes...
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('aula')->nullable();
            $table->foreignId('asignatura_id')->constrained('asignaturas')->cascadeOnDelete();
            $table->timestamps();
        });

        // CRONOGRAMAS (SESIONES / CLASES)
        Schema::create('cronogramas', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->integer('numero_sesion');
            $table->text('observaciones')->nullable();
            
            $table->foreignId('asignatura_id')->constrained('asignaturas')->cascadeOnDelete();
            $table->foreignId('tema_id')->nullable()->constrained('temas')->nullOnDelete();
            
            $table->timestamps();
        });

        // SECUENCIAS DIDACTICAS
        Schema::create('secuencias_didacticas', function (Blueprint $table) {
            $table->id();
            $table->string('momento'); // Inicio, Desarrollo, Cierre
            $table->text('actividad')->nullable();
            $table->string('duracion')->nullable(); // Ej: "15 min"
            $table->foreignId('cronograma_id')->constrained('cronogramas')->cascadeOnDelete();
            $table->timestamps();
        });

        // ESTRATEGIAS DIDACTICAS
        Schema::create('estrategias_didacticas', function (Blueprint $table) {
            $table->id();
            $table->text('metodologicas_docente')->nullable();
            $table->text('aprendizaje_estudiante')->nullable();
            $table->text('recursos')->nullable();
            $table->foreignId('cronograma_id')->constrained('cronogramas')->cascadeOnDelete();
            $table->timestamps();
        });

        // EVALUACIONES
        Schema::create('evaluaciones', function (Blueprint $table) {
            $table->id();
            $table->string('tipo')->nullable(); // Diagnostica, Formativa, Sumativa
            $table->text('actividades')->nullable();
            $table->text('instrumentos')->nullable();
            $table->text('evidencias')->nullable();
            $table->foreignId('cronograma_id')->constrained('cronogramas')->cascadeOnDelete();
            $table->timestamps();
        });

        // ASISTENCIAS
        Schema::create('asistencias', function (Blueprint $table) {
            $table->id();
            $table->boolean('asistio')->default(false);
            $table->foreignId('cronograma_id')->constrained('cronogramas')->cascadeOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
        Schema::dropIfExists('evaluaciones');
        Schema::dropIfExists('estrategias_didacticas');
        Schema::dropIfExists('secuencias_didacticas');
        Schema::dropIfExists('cronogramas');
        Schema::dropIfExists('horarios');
    }
};
