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
        Schema::table('unidades', function (Blueprint $table) {
            $table->enum('tipo', ['TEORIA', 'PRACTICA'])->nullable()->after('titulo');
        });

        Schema::table('temas', function (Blueprint $table) {
            $table->enum('tipo', ['TEORIA', 'PRACTICA'])->nullable()->after('titulo');
        });

        Schema::table('cronogramas', function (Blueprint $table) {
            $table->foreignId('grupo_id')->nullable()->constrained('grupos')->onDelete('cascade')->after('asignatura_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unidades', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });

        Schema::table('temas', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });

        Schema::table('cronogramas', function (Blueprint $table) {
            $table->dropForeign(['grupo_id']);
            $table->dropColumn('grupo_id');
        });
    }
};
