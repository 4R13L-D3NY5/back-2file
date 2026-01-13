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
        Schema::table('carreras', function (Blueprint $table) {
            $table->string('codigo')->nullable()->after('nombre')->index();
            $table->unsignedInteger('sede_id')->nullable()->after('codigo'); // ID based on Frontend mapping
            // Note: We keep 'sede' string column for legacy/fallback if needed, or we could drop it.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carreras', function (Blueprint $table) {
            $table->dropColumn(['codigo', 'sede_id']);
        });
    }
};
