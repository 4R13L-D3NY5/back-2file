<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('generaciones_manuales')) {
            return;
        }

        DB::statement(
            "ALTER TABLE `generaciones_manuales` MODIFY `estado` ENUM('PROGRAMADO','GENERADO','IMPRESO','ENTREGADO','RECIBIDO','DEVUELTO','REVISADO','SUBIDO') NOT NULL DEFAULT 'PROGRAMADO'"
        );

        DB::table('generaciones_manuales')
            ->where('estado', 'RECIBIDO')
            ->update(['estado' => 'DEVUELTO']);

        DB::table('generaciones_manuales')
            ->where('estado', 'PROGRAMADO')
            ->whereNotNull('archivo_examen')
            ->update(['estado' => 'GENERADO']);

        DB::statement(
            "ALTER TABLE `generaciones_manuales` MODIFY `estado` ENUM('PROGRAMADO','GENERADO','IMPRESO','ENTREGADO','DEVUELTO','REVISADO','SUBIDO') NOT NULL DEFAULT 'PROGRAMADO'"
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('generaciones_manuales')) {
            return;
        }

        DB::statement(
            "ALTER TABLE `generaciones_manuales` MODIFY `estado` ENUM('PROGRAMADO','GENERADO','IMPRESO','ENTREGADO','DEVUELTO','REVISADO','RECIBIDO','SUBIDO') NOT NULL DEFAULT 'PROGRAMADO'"
        );

        DB::table('generaciones_manuales')
            ->whereIn('estado', ['GENERADO', 'REVISADO'])
            ->update(['estado' => 'PROGRAMADO']);

        DB::table('generaciones_manuales')
            ->where('estado', 'DEVUELTO')
            ->update(['estado' => 'RECIBIDO']);

        DB::statement(
            "ALTER TABLE `generaciones_manuales` MODIFY `estado` ENUM('PROGRAMADO','IMPRESO','ENTREGADO','RECIBIDO','SUBIDO') NOT NULL DEFAULT 'PROGRAMADO'"
        );
    }
};
