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
            "ALTER TABLE `generaciones_manuales` MODIFY `estado` ENUM('GENERADO','ENTREGADO','DEVUELTO','PROGRAMADO','IMPRESO','RECIBIDO','SUBIDO') NOT NULL DEFAULT 'PROGRAMADO'"
        );

        DB::table('generaciones_manuales')
            ->where('estado', 'GENERADO')
            ->update(['estado' => 'PROGRAMADO']);

        DB::table('generaciones_manuales')
            ->where('estado', 'DEVUELTO')
            ->update(['estado' => 'RECIBIDO']);

        DB::statement(
            "ALTER TABLE `generaciones_manuales` MODIFY `estado` ENUM('PROGRAMADO','IMPRESO','ENTREGADO','RECIBIDO','SUBIDO') NOT NULL DEFAULT 'PROGRAMADO'"
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('generaciones_manuales')) {
            return;
        }

        DB::statement(
            "ALTER TABLE `generaciones_manuales` MODIFY `estado` ENUM('PROGRAMADO','IMPRESO','ENTREGADO','RECIBIDO','SUBIDO','GENERADO','DEVUELTO') NOT NULL DEFAULT 'PROGRAMADO'"
        );

        DB::table('generaciones_manuales')
            ->whereIn('estado', ['PROGRAMADO', 'IMPRESO'])
            ->update(['estado' => 'GENERADO']);

        DB::table('generaciones_manuales')
            ->whereIn('estado', ['RECIBIDO', 'SUBIDO'])
            ->update(['estado' => 'DEVUELTO']);

        DB::statement(
            "ALTER TABLE `generaciones_manuales` MODIFY `estado` ENUM('GENERADO','ENTREGADO','DEVUELTO') NOT NULL DEFAULT 'GENERADO'"
        );
    }
};
