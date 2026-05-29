<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rol_examen_id')->constrained('rol_examenes')->cascadeOnDelete();
            $table->string('public_token', 20)->unique();
            $table->string('estado', 30)->default('PROGRAMADO');
            $table->unsignedTinyInteger('cantidad_variantes')->default(1);
            $table->unsignedSmallInteger('duracion_minutos')->default(45);
            $table->timestamp('generado_en')->nullable();
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('finaliza_en')->nullable();
            $table->timestamp('cerrado_en')->nullable();
            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->json('configuracion')->nullable();
            $table->timestamps();

            $table->unique('rol_examen_id');
            $table->index(['estado', 'public_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_exam_sessions');
    }
};
