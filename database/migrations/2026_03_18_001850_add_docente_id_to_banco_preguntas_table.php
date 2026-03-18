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
        Schema::table('banco_preguntas', function (Blueprint $table) {
            if (!Schema::hasColumn('banco_preguntas', 'docente_id')) {
                $table->foreignId('docente_id')->nullable()->after('asignatura_id')->constrained('docentes')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banco_preguntas', function (Blueprint $table) {
            if (Schema::hasColumn('banco_preguntas', 'docente_id')) {
                $table->dropForeign(['docente_id']);
                $table->dropColumn('docente_id');
            }
        });
    }
};
