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
        // Solo intentar añadir si no existe para evitar errores en el futuro
        Schema::table('rol_examenes', function (Blueprint $table) {
            if (!Schema::hasColumn('rol_examenes', 'sede_id')) {
                $table->foreignId('sede_id')->default(1)->constrained('sedes')->after('carrera_id');
            }
            if (!Schema::hasColumn('rol_examenes', 'estado')) {
                $table->string('estado', 30)->default('programados')->after('fecha');
            }
            if (!Schema::hasColumn('rol_examenes', 'config_generacion')) {
                $table->json('config_generacion')->nullable()->after('estado');
            }
            if (!Schema::hasColumn('rol_examenes', 'timestamps_proceso')) {
                $table->json('timestamps_proceso')->nullable()->after('config_generacion');
            }
            if (!Schema::hasColumn('rol_examenes', 'variantes')) {
                $table->json('variantes')->nullable()->after('timestamps_proceso');
            }
            if (!Schema::hasColumn('rol_examenes', 'patrones')) {
                $table->json('patrones')->nullable()->after('variantes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rol_examenes', function (Blueprint $table) {
            $table->dropForeign(['sede_id']);
            $table->dropColumn([
                'sede_id',
                'estado',
                'config_generacion',
                'timestamps_proceso',
                'variantes',
                'patrones'
            ]);
        });
    }
};
