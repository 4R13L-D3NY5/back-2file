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
        Schema::table('grupos', function (Blueprint $table) {
            // Drop the old constraint that didn't include Sede
            $table->dropUnique('grupo_unico_idx');

            // Add new constraint including Sede
            $table->unique(['gestion', 'asignatura_id', 'nombre', 'tipo', 'sede_id'], 'grupo_sede_unico_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grupos', function (Blueprint $table) {
            $table->dropUnique('grupo_sede_unico_idx');
            $table->unique(['asignatura_id', 'nombre', 'tipo', 'gestion'], 'grupo_unico_idx');
        });
    }
};
