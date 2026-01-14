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
            $table->string('area')->nullable()->after('director_id');
            $table->text('mision')->nullable()->after('area');
            $table->text('vision')->nullable()->after('mision');
            $table->text('perfil_profesional')->nullable()->after('vision');
            $table->string('imagen')->nullable()->after('perfil_profesional');

            // Si 'activo' no existía, lo agregamos. Si existía, no duplicamos.
            if (!Schema::hasColumn('carreras', 'activo')) {
                $table->boolean('activo')->default(true)->after('imagen');
            }
        });
    }

    public function down(): void
    {
        Schema::table('carreras', function (Blueprint $table) {
            $table->dropColumn(['area', 'mision', 'vision', 'perfil_profesional', 'imagen', 'activo']);
        });
    }
};
