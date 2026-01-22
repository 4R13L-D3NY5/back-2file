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
        Schema::table('directors', function (Blueprint $table) {
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->onDelete('set null');
            $table->foreignId('carrera_id')->nullable()->constrained('carreras')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('directors', function (Blueprint $table) {
            $table->dropForeign(['sede_id']);
            $table->dropForeign(['carrera_id']);
            $table->dropColumn(['sede_id', 'carrera_id']);
        });
    }
};
