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
        Schema::table('asignatura_docente', function (Blueprint $table) {
            $table->string('aula')->nullable()->after('grupo');
            $table->string('horario')->nullable()->after('aula');
            $table->integer('cupo')->default(40)->after('horario');
            $table->integer('estudiantes_inscritos')->default(0)->after('cupo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asignatura_docente', function (Blueprint $table) {
            $table->dropColumn(['aula', 'horario', 'cupo', 'estudiantes_inscritos']);
        });
    }
};
