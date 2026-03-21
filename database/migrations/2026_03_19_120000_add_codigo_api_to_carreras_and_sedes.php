<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add codigo_api to carreras table
        Schema::table('carreras', function (Blueprint $table) {
            if (!Schema::hasColumn('carreras', 'codigo_api')) {
                $table->string('codigo_api', 50)->nullable()->after('codigo');
                // Add index for faster lookups
                $table->index('codigo_api');
            }
        });
    }

    public function down(): void
    {
        Schema::table('carreras', function (Blueprint $table) {
            if (Schema::hasColumn('carreras', 'codigo_api')) {
                $table->dropIndex(['codigo_api']);
                $table->dropColumn('codigo_api');
            }
        });
    }
};