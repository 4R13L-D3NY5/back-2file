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
            if (!Schema::hasColumn('banco_preguntas', 'grupoTeorico')) {
                $row->string('grupoTeorico')->nullable()->after('grupo');
            }
        });

        Schema::table('rol_examenes', function (Blueprint $row) {
            if (!Schema::hasColumn('rol_examenes', 'grupoTeorico')) {
                $row->string('grupoTeorico')->nullable()->after('grupo');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banco_preguntas', function (Blueprint $row) {
            if (Schema::hasColumn('banco_preguntas', 'grupoTeorico')) {
                $row->dropColumn('grupoTeorico');
            }
        });

        Schema::table('rol_examenes', function (Blueprint $row) {
            if (Schema::hasColumn('rol_examenes', 'grupoTeorico')) {
                $row->dropColumn('grupoTeorico');
            }
        });
    }
};
