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
        Schema::create('planificaciones_personales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id')->constrained('temas')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id'); // We use this compatible definition for SQLite/MySQL mix
            // No foreign key constraint for user_id to avoid SQLite issues if needed, or we can try constrained if consistent.
            // Given previous issues, simple index is safer, but let's try standard Laravel constrained if users table is uniform.
            // Using flexible definition as seen in previous fix:

            // Textos rich
            $table->text('estrategias_metodologicas')->nullable();
            $table->text('estrategias_aprendizaje')->nullable();

            // JSONs
            $table->json('estrategias_recursos')->nullable();
            $table->json('evaluacion_formativa')->nullable();
            $table->json('evaluacion_sumativa')->nullable();
            $table->json('secuencia_didactica')->nullable();

            $table->timestamps();

            // Indexes
            $table->unique(['tema_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planificaciones_personales');
    }
};
