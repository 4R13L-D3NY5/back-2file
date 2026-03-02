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
        Schema::table('seguimientos', function (Blueprint $table) {
            $table->boolean('es_examen')->default(false)->after('pedagogico');
            $table->string('tipo_examen')->nullable()->after('es_examen');
            $table->json('georeferencia')->nullable()->after('tipo_examen'); // Lat, Lng, Accuracy, Timestamp
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seguimientos', function (Blueprint $table) {
            $table->dropColumn(['es_examen', 'tipo_examen', 'georeferencia']);
        });
    }
};
