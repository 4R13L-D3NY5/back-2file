<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $configs = DB::table('banco_preguntas_configuraciones')
            ->whereNull('sede_id')
            ->orderBy('id')
            ->get();

        foreach ($configs as $config) {
            $sedeIds = DB::table('banco_preguntas')
                ->where('asignatura_id', $config->asignatura_id)
                ->where('grupoTeorico', $config->grupo_teorico)
                ->where('parcial', $config->parcial)
                ->whereNotNull('sede_id')
                ->distinct()
                ->pluck('sede_id')
                ->values();

            if ($sedeIds->count() === 1) {
                $sedeId = (int) $sedeIds->first();
                $exists = DB::table('banco_preguntas_configuraciones')
                    ->where('asignatura_id', $config->asignatura_id)
                    ->where('sede_id', $sedeId)
                    ->where('grupo_teorico', $config->grupo_teorico)
                    ->where('parcial', $config->parcial)
                    ->exists();

                if (! $exists) {
                    DB::table('banco_preguntas_configuraciones')
                        ->where('id', $config->id)
                        ->update(['sede_id' => $sedeId]);
                    continue;
                }
            }

            DB::table('banco_preguntas_configuraciones')
                ->where('id', $config->id)
                ->delete();
        }
    }

    public function down(): void
    {
        //
    }
};
