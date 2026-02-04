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
            if (!Schema::hasColumn('grupos', 'id_horario_api')) {
                // Campo para almacenar el idHorario único de la API externa
                // Permite identificación precisa para sincronización
                $table->unsignedBigInteger('id_horario_api')->nullable()->after('id');
                $table->unique('id_horario_api', 'grupos_id_horario_api_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grupos', function (Blueprint $table) {
            if (Schema::hasColumn('grupos', 'id_horario_api')) {
                $table->dropUnique('grupos_id_horario_api_unique');
                $table->dropColumn('id_horario_api');
            }
        });
    }
};
