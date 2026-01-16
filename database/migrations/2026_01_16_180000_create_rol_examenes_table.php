<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla para almacenar el rol de exámenes subido por Director de Carrera
     */
    public function up(): void
    {
        Schema::create('rol_examenes', function (Blueprint $table) {
            $table->id();
            $table->string('gestion', 20);           // '2026-I', '2025-II'
            $table->foreignId('carrera_id')->constrained('carreras');
            $table->string('materia_codigo', 50);
            $table->string('materia_nombre', 255);
            $table->enum('tipo_examen', ['1er Parcial', '2do Parcial', 'Final', '2da Instancia']);
            $table->unsignedTinyInteger('semana');   // 1-20
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('aula', 50)->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            // Índices para búsquedas frecuentes
            $table->index(['gestion', 'carrera_id']);
            $table->index('materia_codigo');
            $table->index('semana');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rol_examenes');
    }
};
