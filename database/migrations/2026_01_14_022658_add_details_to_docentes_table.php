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
        Schema::table('docentes', function (Blueprint $table) {
            $table->string('email')->nullable()->unique();
            $table->string('foto')->nullable();
            $table->string('especialidad')->nullable();
            $table->string('grado_academico')->nullable(); // Lic., Ing., MSc.
            $table->string('tipo_dedicacion')->default('TIEMPO_PARCIAL'); // TIEMPO_COMPLETO, MEDIO_TIEMPO

            $table->unsignedBigInteger('sede_id')->nullable();
            $table->foreign('sede_id')->references('id')->on('sedes')->nullOnDelete();

            $table->boolean('estado')->default(true); // Activo/Inactivo
        });
    }

    public function down(): void
    {
        Schema::table('docentes', function (Blueprint $table) {
            $table->dropForeign(['sede_id']);
            $table->dropColumn(['email', 'foto', 'especialidad', 'grado_academico', 'tipo_dedicacion', 'sede_id', 'estado']);
        });
    }
};
