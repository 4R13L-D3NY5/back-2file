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
        Schema::table('informe_semanals', function (Blueprint $table) {
            $table->boolean('es_propagado')->default(false)->after('cumplimiento_porcentaje');
            $table->foreignId('propagado_de_id')->nullable()->after('es_propagado')->constrained('informe_semanals')->nullOnDelete();
            
            // Index for faster queries on propagation tracking
            $table->index(['es_propagado', 'propagado_de_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('informe_semanals', function (Blueprint $table) {
            $table->dropForeign(['propagado_de_id']);
            $table->dropIndex(['es_propagado', 'propagado_de_id']);
            $table->dropColumn(['es_propagado', 'propagado_de_id']);
        });
    }
};
