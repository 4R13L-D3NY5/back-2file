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
        Schema::table('banco_preguntas', function (Blueprint $row) {
            if (!Schema::hasColumn('banco_preguntas', 'sede_id')) {
                $row->unsignedBigInteger('sede_id')->nullable()->after('docente_id');
                $row->index('sede_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banco_preguntas', function (Blueprint $row) {
            if (Schema::hasColumn('banco_preguntas', 'sede_id')) {
                $row->dropColumn('sede_id');
            }
        });
    }
};
