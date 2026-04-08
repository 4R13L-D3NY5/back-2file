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
        Schema::table('carreras', function (Blueprint $table) {
            if (!Schema::hasColumn('carreras', 'plan_estudios')) {
                $table->string('plan_estudios', 10)->nullable()->after('codigo');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carreras', function (Blueprint $table) {
            if (Schema::hasColumn('carreras', 'plan_estudios')) {
                $table->dropColumn('plan_estudios');
            }
        });
    }
};