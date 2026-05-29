<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_exam_attempt_id')->constrained('virtual_exam_attempts')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero_pregunta');
            $table->unsignedBigInteger('banco_pregunta_id')->nullable();
            $table->json('respuesta_marcada')->nullable();
            $table->json('respuesta_correcta')->nullable();
            $table->boolean('es_correcta')->default(false);
            $table->timestamps();

            $table->unique(['virtual_exam_attempt_id', 'numero_pregunta'], 'virtual_answer_unique_question');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_exam_answers');
    }
};
