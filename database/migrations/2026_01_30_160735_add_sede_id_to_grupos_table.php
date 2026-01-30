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
        if (!Schema::hasColumn('grupos', 'sede_id')) {
            Schema::table('grupos', function (Blueprint $table) {
                $table->foreignId('sede_id')->nullable()->after('docente_id')->constrained('sedes');
            });
        }

        // Intentar poblar datos existentes desde el docente asignado
        try {
            DB::statement("UPDATE grupos INNER JOIN docentes ON grupos.docente_id = docentes.id SET grupos.sede_id = docentes.sede_id WHERE grupos.sede_id IS NULL");
        } catch (\Exception $e) {
            // Ignorar si falla por alguna razón estructural en el momento
        }

        // Intentar poblar desde el aula si aún es null (docente sin sede o sin docente)
        try {
            DB::statement("
                UPDATE grupos
                INNER JOIN horarios ON horarios.grupo_id = grupos.id
                INNER JOIN aulas ON horarios.aula_id = aulas.id
                INNER JOIN bloques ON aulas.bloque_id = bloques.id
                SET grupos.sede_id = bloques.sede_id
                WHERE grupos.sede_id IS NULL
            ");
        } catch (\Exception $e) {
            // Ignorar
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grupos', function (Blueprint $table) {
            $table->dropForeign(['sede_id']);
            $table->dropColumn('sede_id');
        });
    }
};
