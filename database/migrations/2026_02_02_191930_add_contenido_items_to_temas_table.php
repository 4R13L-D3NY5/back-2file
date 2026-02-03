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
        // Add contenido_items column to temas table
        Schema::table('temas', function (Blueprint $table) {
            $table->json('contenido_items')->nullable();
        });

        // Migrar datos existentes: convertir descripcion a contenido_items (si existe)
        if (Schema::hasColumn('temas', 'descripcion')) {
            DB::table('temas')->whereNotNull('descripcion')->each(function ($tema) {
                // Dividir por saltos de línea y limpiar
                $items = array_filter(
                    array_map('trim', explode("\n", $tema->descripcion)),
                    fn($item) => !empty($item)
                );
                
                if (!empty($items)) {
                    DB::table('temas')
                        ->where('id', $tema->id)
                        ->update(['contenido_items' => json_encode($items)]);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('temas', function (Blueprint $table) {
            $table->dropColumn('contenido_items');
        });
    }
};
