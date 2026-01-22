<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DIRECTORES
        Schema::create('directors', function (Blueprint $table) {
            $table->id();
            $table->string('nombres');
            $table->string('apellidos');
            $table->string('titulo')->nullable(); // Ej: Ing., Lic.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        // DOCENTES
        Schema::create('docentes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_completo');
            $table->string('celular')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        // ESTUDIANTES
        Schema::create('estudiantes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique(); // ID Externo
            $table->string('nombres');
            $table->string('apellidos');
            // No tiene user_id por ahora según ERD
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estudiantes');
        Schema::dropIfExists('docentes');
        Schema::dropIfExists('directors');
    }
};
