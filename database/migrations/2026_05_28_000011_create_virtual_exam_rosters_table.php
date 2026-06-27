<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_exam_rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_exam_session_id')->constrained('virtual_exam_sessions')->cascadeOnDelete();
            $table->string('codigo_estudiante', 60);
            $table->string('nombre_estudiante', 255);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['virtual_exam_session_id', 'codigo_estudiante'], 'virtual_roster_unique_student');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_exam_rosters');
    }
};
