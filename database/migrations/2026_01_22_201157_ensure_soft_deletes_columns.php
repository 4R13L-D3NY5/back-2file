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
        Schema::table('docentes', function (Blueprint $table) {
            if (!Schema::hasColumn('docentes', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('carreras', function (Blueprint $table) {
            if (!Schema::hasColumn('carreras', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('asignaturas', function (Blueprint $table) {
            if (!Schema::hasColumn('asignaturas', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('docentes', function (Blueprint $table) {
            if (Schema::hasColumn('docentes', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::table('carreras', function (Blueprint $table) {
            if (Schema::hasColumn('carreras', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::table('asignaturas', function (Blueprint $table) {
            if (Schema::hasColumn('asignaturas', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
