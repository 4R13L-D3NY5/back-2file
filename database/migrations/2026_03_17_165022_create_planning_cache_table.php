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
        Schema::create('planning_cache', function (Blueprint $table) {
            $table->id();

            // Identificador unico del horario en la API Planning
            $table->unsignedBigInteger('id_horario_api')->index();
            $table->unsignedBigInteger('id_designacion')->default(0);

            // Datos del docente
            $table->string('docente_nombre')->nullable();
            $table->string('docente_ci', 20)->nullable()->index();

            // Datos de la materia
            $table->string('sigla', 30)->index();
            $table->string('materia');
            $table->unsignedTinyInteger('semestre')->default(0);

            // Grupo y horario
            $table->string('grupo', 20)->nullable();
            $table->string('tipo_clase', 30)->nullable(); // Teorico, Practico, Laboratorio
            $table->string('dia', 20)->nullable();
            $table->string('hora_inicio', 10)->nullable();
            $table->string('hora_fin', 10)->nullable();

            // Ubicacion
            $table->string('aula', 100)->nullable();
            $table->string('bloque', 100)->nullable();
            $table->unsignedInteger('capacidad_aula')->nullable();

            // Carrera y sede
            $table->string('carrera_codigo', 30)->index(); // ej: CARENL, CARSIS
            $table->unsignedInteger('sede_api_id')->index(); // idSede de la API
            $table->string('sede_nombre', 100)->nullable();

            // Gestion y plan
            $table->string('gestion', 20)->index(); // ej: 1-2026
            $table->string('plan_estudios', 5)->default('N'); // A, N

            // Metadatos de sincronizacion
            $table->timestamp('sincronizado_at')->nullable();

            $table->timestamps();

            // Indices compuestos para busquedas frecuentes
            $table->index(['gestion', 'carrera_codigo', 'sede_api_id'], 'pc_gestion_carrera_sede');
            $table->index(['sigla', 'plan_estudios'], 'pc_sigla_plan');
            $table->index(['gestion', 'sede_api_id', 'plan_estudios'], 'pc_gestion_sede_plan');
        });

        // Tabla para registrar las sincronizaciones realizadas
        Schema::create('planning_sync_log', function (Blueprint $table) {
            $table->id();
            $table->string('gestion', 20);
            $table->unsignedInteger('sede_api_id');
            $table->string('carrera_codigo', 30)->nullable(); // null = todas
            $table->unsignedInteger('total_registros')->default(0);
            $table->unsignedInteger('total_carreras')->default(0);
            $table->unsignedInteger('user_id')->nullable();
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planning_sync_log');
        Schema::dropIfExists('planning_cache');
    }
};
