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
        Schema::create('informe_semanals', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('grupo_id')->constrained('grupos')->onDelete('cascade');
            $table->foreignId('docente_id')->constrained('docentes')->onDelete('cascade');
            
            $table->date('semana_inicio');
            $table->date('semana_fin');
            
            // JSON column to store the 7 criteria evaluation
            // Structure: { "tema_impartido": { "cumple": true, "obs": "..." }, ... }
            $table->json('criterios')->nullable();
            
            $table->text('observaciones')->nullable();
            
            $table->enum('escala_alerta', ['VERDE', 'AMARILLO', 'ROJO'])->default('VERDE');
            $table->integer('cumplimiento_porcentaje')->default(0); // 0-100
            
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('informe_semanals');
    }
};
