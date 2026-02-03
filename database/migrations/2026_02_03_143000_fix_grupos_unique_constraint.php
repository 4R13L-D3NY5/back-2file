<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop old constraint safely
        try {
            Schema::table('grupos', function (Blueprint $table) {
                $table->dropUnique('grupo_unico_idx');
            });
        } catch (\Exception $e) {
            // Index might already be gone from previous failed attempt
        }

        // 2. Add new constraint with limited key lengths to fit in 3072 bytes (or 1000 depending on version)
        // gestion(20) + asignatura_id(8) + nombre(50) + tipo(20) + sede_id(8) << 1000 bytes
        DB::statement('ALTER TABLE grupos ADD UNIQUE INDEX grupos_sede_unico_idx (gestion(20), asignatura_id, nombre(50), tipo(20), sede_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::statement('DROP INDEX grupos_sede_unico_idx ON grupos');
        } catch (\Exception $e) {
        }

        try {
            Schema::table('grupos', function (Blueprint $table) {
                $table->unique(['asignatura_id', 'nombre', 'tipo', 'gestion'], 'grupo_unico_idx');
            });
        } catch (\Exception $e) {
        }
    }
};
