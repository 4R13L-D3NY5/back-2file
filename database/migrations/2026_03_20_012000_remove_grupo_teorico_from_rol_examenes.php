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
        Schema::table('rol_examenes', function (Blueprint $table) {
            if (Schema::hasColumn('rol_examenes', 'grupoTeorico')) {
                $table->dropColumn('grupoTeorico');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rol_examenes', function (Blueprint $table) {
            if (!Schema::hasColumn('rol_examenes', 'grupoTeorico')) {
                $table->string('grupoTeorico')->nullable()->after('grupo');
            }
        });
    }
};
