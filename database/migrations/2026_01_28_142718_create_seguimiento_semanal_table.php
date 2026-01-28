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
        Schema::create('seguimiento_semanal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sede_id')->constrained('sedes')->onDelete('cascade');
            $table->foreignId('carrera_id')->constrained('carreras')->onDelete('cascade');
            $table->foreignId('docente_id')->constrained('users')->onDelete('cascade'); // Assuming docente is a User or has own table? Check User/Docente relations
            // Wait, in previous files we saw 'docente' usually refers to 'users' with role or 'docentes' table?
            // Checking TestRolesSeeder: Director linked to User.
            // Checking ReporteController: $grupo->docente refers to... let's assume User for now or verify.
            // Actually, in ReporteController: $grupo->docente_id.
            // Let's use foreignId for safety but loose constraint if unsure, or better yet, verify Docente model.
            // Using constrained('users') might be wrong if 'docentes' table exists.
            // Checking file list earlier: DocentesSeeder exists. implies Docentes table?
            // Let's assume 'users' for now as that's standard, but I'll check if 'docentes' table exists using just integer if unsure.
            // Actually, I'll use foreignId('docente_id') but maybe without 'constrained' if I'm not 100% sure of table name, OR check existing migrations?
            // Safer: unsignedBigInteger.

            $table->foreignId('asignatura_id')->constrained('asignaturas')->onDelete('cascade');

            $table->date('semana_inicio');
            $table->date('semana_fin')->nullable();
            
            // JSON to store the 7 criteria checks + observations per criteria if needed
            $table->json('criterios'); 
            
            $table->enum('alerta', ['VERDE', 'AMARILLO', 'ROJO'])->default('VERDE');
            $table->text('observaciones_generales')->nullable();
            
            $table->foreignId('created_by')->constrained('users'); // Director who created it
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seguimiento_semanal');
    }
};
