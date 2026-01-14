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
        Schema::create('asignatura_docente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_id')->constrained('asignaturas')->cascadeOnDelete();
            $table->foreignId('docente_id')->constrained('docentes')->cascadeOnDelete();

            // Optional: Group Logic
            $table->string('grupo')->nullable()->default('GR-1'); // GR-1, GR-2

            $table->timestamps();

            $table->unique(['asignatura_id', 'docente_id', 'grupo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignatura_docente');
    }
};
