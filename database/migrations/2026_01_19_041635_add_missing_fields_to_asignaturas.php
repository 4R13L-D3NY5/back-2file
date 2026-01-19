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
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->text('competencia_global_especifica')->nullable();
            $table->text('reglamento_normativa')->nullable();
            $table->text('organizacion_calendario')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->dropColumn('competencia_global_especifica');
            $table->dropColumn('reglamento_normativa');
            $table->dropColumn('organizacion_calendario');
        });
    }
};
