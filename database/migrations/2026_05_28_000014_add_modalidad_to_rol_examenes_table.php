<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rol_examenes', function (Blueprint $table) {
            if (! Schema::hasColumn('rol_examenes', 'modalidad')) {
                $table->string('modalidad', 40)
                    ->default('PRESENCIAL_CON_CARTILLA')
                    ->after('estado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rol_examenes', function (Blueprint $table) {
            if (Schema::hasColumn('rol_examenes', 'modalidad')) {
                $table->dropColumn('modalidad');
            }
        });
    }
};
