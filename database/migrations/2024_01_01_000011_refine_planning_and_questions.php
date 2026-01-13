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
        // 1. Permitir Cronogramas sin fecha (Plan Maestro / Temario Secuencial)
        Schema::table('cronogramas', function (Blueprint $table) {
            $table->date('fecha')->nullable()->change();
        });

        // 2. Auditoría en Banco de Preguntas
        Schema::table('banco_preguntas', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('logro_esperado_id');
            // $table->foreign('created_by')->references('id')->on('users'); // Si existiera tabla users
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cronogramas', function (Blueprint $table) {
            $table->date('fecha')->nullable(false)->change();
        });

        Schema::table('banco_preguntas', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
    }
};
