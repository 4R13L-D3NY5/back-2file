<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banco_preguntas_configuraciones', function (Blueprint $table) {
            if (! Schema::hasColumn('banco_preguntas_configuraciones', 'sede_id')) {
                $table->unsignedBigInteger('sede_id')->nullable()->after('asignatura_id');
            }
        });

        Schema::table('banco_preguntas_configuraciones', function (Blueprint $table) {
            try {
                $table->dropUnique('banco_config_unique');
            } catch (\Throwable $e) {
                // Algunas instalaciones ya pueden tener otro indice por despliegues previos.
            }

            $table->index('sede_id', 'banco_config_sede_idx');
            $table->unique(
                ['asignatura_id', 'sede_id', 'grupo_teorico', 'parcial'],
                'banco_config_sede_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('banco_preguntas_configuraciones', function (Blueprint $table) {
            try {
                $table->dropUnique('banco_config_sede_unique');
            } catch (\Throwable $e) {
                //
            }

            try {
                $table->dropIndex('banco_config_sede_idx');
            } catch (\Throwable $e) {
                //
            }

            $table->unique(['asignatura_id', 'grupo_teorico', 'parcial'], 'banco_config_unique');
        });

        Schema::table('banco_preguntas_configuraciones', function (Blueprint $table) {
            if (Schema::hasColumn('banco_preguntas_configuraciones', 'sede_id')) {
                $table->dropColumn('sede_id');
            }
        });
    }
};
