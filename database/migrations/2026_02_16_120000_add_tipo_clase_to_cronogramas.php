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
        Schema::table('cronogramas', function (Blueprint $table) {
            if (!Schema::hasColumn('cronogramas', 'tipo_clase')) {
                $table->string('tipo_clase')->nullable()->after('tema_id'); // Or after an appropriate column
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cronogramas', function (Blueprint $table) {
            if (Schema::hasColumn('cronogramas', 'tipo_clase')) {
                $table->dropColumn('tipo_clase');
            }
        });
    }
};
