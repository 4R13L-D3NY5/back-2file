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
        Schema::table('logros_esperados', function (Blueprint $table) {
            $table->string('periodo')->nullable()->after('tipo_logro')->comment('Ej: 1er Parcial, 2do Parcial, Final');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logros_esperados', function (Blueprint $table) {
            $table->dropColumn('periodo');
        });
    }
};
