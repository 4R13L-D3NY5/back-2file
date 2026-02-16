<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seguimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cronograma_id')->constrained('cronogramas')->cascadeOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->date('fecha')->nullable();
            $table->boolean('cumplido')->default(false);
            $table->boolean('tema_cumplido')->default(false);
            $table->string('estado_cumplimiento')->default('NO'); // TOTAL, PARCIAL, NO
            $table->text('observaciones')->nullable();
            $table->json('pedagogico')->nullable(); // Checkboxes, estrategias, evaluación, secuencia
            $table->json('evidencias')->nullable(); // Archivos subidos
            $table->json('integracion_transversal')->nullable();
            $table->timestamps();

            // One follow-up per session per group
            $table->unique(['cronograma_id', 'grupo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seguimientos');
    }
};
