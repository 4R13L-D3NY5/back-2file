<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega indice_tipo a la tabla cronogramas.
     *
     * indice_tipo representa la posición ordinal de la sesión dentro de su tipo
     * en una semana determinada (1 = 1ra Teórica/Práctica de la semana,
     * 2 = 2da Teórica/Práctica, etc.).
     *
     * Este campo es clave para el matching correcto entre docentes:
     * la "1ra Teórica de la semana 1" del Docente A siempre se mapea
     * a la "1ra Teórica de la semana 1" del Docente B,
     * sin importar el numero_sesion global de cada uno.
     */
    public function up(): void
    {
        Schema::table('cronogramas', function (Blueprint $table) {
            $table->unsignedTinyInteger('indice_tipo')->nullable()->after('tipo_clase');
        });
    }

    public function down(): void
    {
        Schema::table('cronogramas', function (Blueprint $table) {
            $table->dropColumn('indice_tipo');
        });
    }
};
