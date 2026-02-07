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
        Schema::table('asignaturas', function (Blueprint $table) {
            if (!Schema::hasColumn('asignaturas', 'comun_tipo')) {
                $table->enum('comun_tipo', ['fusionada', 'espejo'])->nullable()->after('comun_token');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asignaturas', function (Blueprint $table) {
            if (Schema::hasColumn('asignaturas', 'comun_tipo')) {
                $table->dropColumn('comun_tipo');
            }
        });
    }
};
