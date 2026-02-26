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
        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_id')->constrained('asignaturas')->onDelete('cascade');
            $table->foreignId('docente_id')->constrained('docentes')->onDelete('cascade');
            $table->foreignId('auditor_id')->constrained('users')->onDelete('cascade');
            $table->string('semana')->comment('Semana académica, ej: Semana 1');
            $table->string('tipo')->comment('Ej: Microcurricular, Practica');
            $table->json('criterios')->nullable()->comment('Lista de cotejo en JSON');
            $table->text('observaciones')->nullable();
            $table->text('acciones_correctivas')->nullable();
            $table->enum('semaforo', ['verde', 'amarillo', 'rojo'])->default('verde');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};
