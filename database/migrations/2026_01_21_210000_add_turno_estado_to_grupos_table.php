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
        Schema::table('grupos', function (Blueprint $table) {
            if (!Schema::hasColumn('grupos', 'turno')) {
                $table->string('turno')->nullable()->after('tipo');
            }
            if (!Schema::hasColumn('grupos', 'estado')) {
                $table->string('estado')->default('ACTIVO')->after('turno');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grupos', function (Blueprint $table) {
            if (Schema::hasColumn('grupos', 'turno')) {
                $table->dropColumn('turno');
            }
            if (Schema::hasColumn('grupos', 'estado')) {
                $table->dropColumn('estado');
            }
        });
    }
};
