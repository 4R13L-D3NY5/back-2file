<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates many-to-many relationship between Asignaturas and Carreras
     * with semestre stored in pivot (same subject may be in different semester per career/sede)
     */
    public function up(): void
    {
        // 1. Create the pivot table
        if (!Schema::hasTable('asignatura_carrera')) {
            Schema::create('asignatura_carrera', function (Blueprint $table) {
                $table->id();
                $table->foreignId('asignatura_id')->constrained('asignaturas')->cascadeOnDelete();
                $table->foreignId('carrera_id')->constrained('carreras')->cascadeOnDelete();
                $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
                $table->integer('semestre')->nullable();
                $table->timestamps();

                $table->unique(['asignatura_id', 'carrera_id', 'sede_id'], 'asig_carrera_sede_unique');
            });
        }

        // 2. Remove carrera_id and semestre from asignaturas (moved to pivot)
        Schema::table('asignaturas', function (Blueprint $table) {
            // Drop FK constraint first
            if (Schema::hasColumn('asignaturas', 'carrera_id')) {
                $table->dropForeign(['carrera_id']);
                $table->dropColumn('carrera_id');
            }
            if (Schema::hasColumn('asignaturas', 'semestre')) {
                $table->dropColumn('semestre');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Restore columns to asignaturas
        Schema::table('asignaturas', function (Blueprint $table) {
            if (!Schema::hasColumn('asignaturas', 'carrera_id')) {
                $table->foreignId('carrera_id')->nullable()->constrained('carreras')->nullOnDelete();
            }
            if (!Schema::hasColumn('asignaturas', 'semestre')) {
                $table->string('semestre')->nullable();
            }
        });

        // 2. Drop pivot table
        Schema::dropIfExists('asignatura_carrera');
    }
};
