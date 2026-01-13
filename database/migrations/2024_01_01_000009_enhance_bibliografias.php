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
        Schema::table('bibliografias', function (Blueprint $table) {
            $table->string('editorial')->nullable()->after('autor');
            $table->string('edicion')->nullable()->after('editorial');
            $table->string('isbn')->nullable()->after('tipo');
            $table->string('paginas')->nullable()->comment('Total paginas o rango referencial')->after('isbn');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bibliografias', function (Blueprint $table) {
            $table->dropColumn(['editorial', 'edicion', 'isbn', 'paginas']);
        });
    }
};
