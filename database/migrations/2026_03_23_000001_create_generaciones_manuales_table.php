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
        Schema::create('generaciones_manuales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->foreignId('carrera_id')->nullable()->constrained('carreras')->nullOnDelete();
            $table->foreignId('asignatura_id')->nullable()->constrained('asignaturas')->nullOnDelete();
            $table->foreignId('docente_id')->nullable()->constrained('docentes')->nullOnDelete();
            
            $table->string('sede_nombre')->nullable();
            $table->string('carrera_nombre')->nullable();
            $table->string('asignatura_nombre')->nullable();
            $table->string('docente_nombre')->nullable();
            
            $table->string('parcial');
            $table->string('gestion')->nullable();
            $table->string('grupo')->nullable();
            $table->string('hora')->nullable();
            $table->integer('cant_variantes')->default(1);
            $table->date('fecha_examen');
            
            $table->text('motivo');
            $table->enum('estado', ['GENERADO', 'ENTREGADO', 'DEVUELTO'])->default('GENERADO');
            
            $table->json('patron_respuestas_json')->nullable();
            $table->json('configuracion_json')->nullable();
            
            $table->string('archivo_examen')->nullable();
            $table->string('archivo_patron_pdf')->nullable();
            $table->json('archivos_patron_xlsx')->nullable();
            
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generaciones_manuales');
    }
};
