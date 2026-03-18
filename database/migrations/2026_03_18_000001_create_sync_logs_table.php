<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->string('carrera', 20)->nullable();        // e.g. CARSIS, null = todas
            $table->string('gestion', 20)->default('1-2026');
            $table->enum('modo', ['carrera', 'sede', 'materia'])->default('carrera');
            $table->enum('estado', ['ok', 'error', 'parcial'])->default('ok');
            $table->integer('total_registros')->default(0);
            $table->integer('docentes_creados')->default(0);
            $table->integer('grupos_creados')->default(0);
            $table->integer('horarios_actualizados')->default(0);
            $table->text('error_mensaje')->nullable();
            $table->decimal('duracion_segundos', 8, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sede_id', 'carrera']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
