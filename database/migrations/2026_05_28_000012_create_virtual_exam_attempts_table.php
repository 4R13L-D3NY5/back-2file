<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_exam_session_id')->constrained('virtual_exam_sessions')->cascadeOnDelete();
            $table->foreignId('virtual_exam_roster_id')->nullable()->constrained('virtual_exam_rosters')->nullOnDelete();
            $table->string('codigo_estudiante', 60);
            $table->string('nombre_estudiante', 255);
            $table->string('variante', 5);
            $table->string('estado', 30)->default('INGRESADO');
            $table->string('access_token', 80)->unique();
            $table->string('download_token', 80)->unique();
            $table->timestamp('ingresado_en')->nullable();
            $table->timestamp('finalizado_en')->nullable();
            $table->timestamp('expira_en')->nullable();
            $table->unsignedSmallInteger('respuestas_total')->default(0);
            $table->unsignedSmallInteger('correctas_total')->default(0);
            $table->json('patron_estudiante')->nullable();
            $table->string('ip_address', 80)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->unique(['virtual_exam_session_id', 'codigo_estudiante'], 'virtual_attempt_unique_student');
            $table->index(['virtual_exam_session_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_exam_attempts');
    }
};
