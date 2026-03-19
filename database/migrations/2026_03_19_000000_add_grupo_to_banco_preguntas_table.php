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
            if (!Schema::hasColumn('banco_preguntas', 'grupo')) {
                $table->string('grupo')->nullable()->after('tipo')->comment('Grupo o categoría para problemas/subproblemas');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banco_preguntas', function (Blueprint $table) {
            if (Schema::hasColumn('banco_preguntas', 'grupo')) {
                $table->dropColumn('grupo');
            }
        });
    }
};
