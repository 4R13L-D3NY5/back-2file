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
            if (!Schema::hasColumn('carreras', 'sede_id')) {
                $table->unsignedBigInteger('sede_id')->nullable()->after('director_id');
                $table->foreign('sede_id')->references('id')->on('sedes')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('carreras', function (Blueprint $table) {
            $table->dropForeign(['sede_id']);
            $table->dropColumn('sede_id');
        });
    }
};
