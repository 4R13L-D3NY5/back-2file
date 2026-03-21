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
        Schema::table('horarios', function (Blueprint $table) {
            if (!Schema::hasColumn('horarios', 'modificado_localmente')) {
                $table->boolean('modificado_localmente')->default(false)->after('id_horario_api');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('horarios', function (Blueprint $table) {
            if (Schema::hasColumn('horarios', 'modificado_localmente')) {
                $table->dropColumn('modificado_localmente');
            }
        });
    }
};