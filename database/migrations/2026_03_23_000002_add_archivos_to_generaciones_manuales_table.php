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
        Schema::table('generaciones_manuales', function (Blueprint $table) {
            // Verificar si no existen para evitar duplicados en caso de que ya se hayan creado
            if (!Schema::hasColumn('generaciones_manuales', 'archivo_examen')) {
                $table->string('archivo_examen')->nullable()->after('configuracion_json');
            }
            if (!Schema::hasColumn('generaciones_manuales', 'archivo_patron_pdf')) {
                $table->string('archivo_patron_pdf')->nullable()->after('archivo_examen');
            }
            if (!Schema::hasColumn('generaciones_manuales', 'archivos_patron_xlsx')) {
                $table->json('archivos_patron_xlsx')->nullable()->after('archivo_patron_pdf');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generaciones_manuales', function (Blueprint $table) {
            $table->dropColumn(['archivo_examen', 'archivo_patron_pdf', 'archivos_patron_xlsx']);
        });
    }
};
