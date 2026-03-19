<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            // JSON con el diff detallado: grupos nuevos, cambios de docente,
            // horarios cambiados/eliminados, docentes/users nuevos, asignaturas nuevas
            $table->longText('diff_data')->nullable()->after('error_mensaje');
        });
    }

    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropColumn('diff_data');
        });
    }
};
