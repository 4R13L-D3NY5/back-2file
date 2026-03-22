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
        Schema::create('director_carrera', function (Blueprint $table) {
            $table->id();
            $table->foreignId('director_id')->constrained('directors')->onDelete('cascade');
            $table->foreignId('carrera_id')->constrained('carreras')->onDelete('cascade');
            $table->boolean('es_principal')->default(false);
            $table->timestamps();
            
            // Índice único para evitar duplicados
            $table->unique(['director_id', 'carrera_id'], 'director_carrera_unique');
            
            // Índice para búsquedas rápidas por carrera
            $table->index(['carrera_id', 'es_principal'], 'idx_carrera_principal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('director_carrera');
    }
};