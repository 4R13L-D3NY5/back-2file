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
        Schema::table('temas', function (Blueprint $table) {
            $table->text('resultado_aprendizaje')->nullable()->after('titulo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('temas', function (Blueprint $table) {
            $table->dropColumn('resultado_aprendizaje');
        });
    }
};
