<?php
/**
 * RESTORE_PEDAGOGY_DEEP.php
 * ─────────────────────────────────────────────────────────────
 * Restaura el contenido pedagógico profundo: campos de texto en 
 * asignaturas, planificaciones personales, logros esperados, 
 * indicadores (vinculados a temas) y seguimientos (al cronograma).
 * ─────────────────────────────────────────────────────────────
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

echo "=== INICIANDO RESTAURACIÓN PEDAGÓGICA PROFUNDA ===\n";

$recreatedAsigs = DB::table("$dbCurrent.asignaturas")
    ->where('created_at', '>=', now()->subHours(12))
    ->get();

$stats = [
    'asignaturas_textos' => 0,
    'planificaciones' => 0,
    'logros' => 0,
    'indicadores' => 0,
    'seguimientos' => 0,
    'errores' => 0
];

foreach ($recreatedAsigs as $asigC) {
    // 1. Encontrar equivalente en backup
    $asigB = DB::table("$dbBackup.asignaturas")
        ->where('nombre', $asigC->nombre)
        ->where('plan_estudios', $asigC->plan_estudios)
        ->first();
        
    if (!$asigB) continue;

    DB::beginTransaction();
    try {
        // --- 1. Textos Raíz ---
        $arrC = (array)$asigC;
        $arrB = (array)$asigB;
        $needUpdateText = empty($arrC['justificacion']) && empty($arrC['proposito_general']) && !empty($arrB['justificacion']);
        
        if ($needUpdateText) {
            $cols = \Illuminate\Support\Facades\Schema::connection('mysql')->getColumnListing('asignaturas');
            $updateData = [];
            foreach (['justificacion', 'proposito_general', 'metodologia_general', 'objetivo_general', 'evaluacion_general'] as $col) {
                if (in_array($col, $cols) && array_key_exists($col, $arrB)) {
                    $updateData[$col] = $arrB[$col];
                }
            }
            if (!empty($updateData)) {
                DB::table("$dbCurrent.asignaturas")->where('id', $asigC->id)->update($updateData);
                $stats['asignaturas_textos']++;
            }
        }

        // --- 2. Árbol de Temas (Planificaciones, Logros, Indicadores) ---
        $unidadesC = DB::table("$dbCurrent.unidades")->where('asignatura_id', $asigC->id)->get();
        $unidadesB = DB::table("$dbBackup.unidades")->where('asignatura_id', $asigB->id)->get();

        foreach ($unidadesB as $ub) {
            $uc = $unidadesC->firstWhere('titulo', $ub->titulo);
            if (!$uc) continue;

            $temasB = DB::table("$dbBackup.temas")->where('unidad_id', $ub->id)->get();
            $temasC = DB::table("$dbCurrent.temas")->where('unidad_id', $uc->id)->get();

            foreach ($temasB as $tb) {
                // Emparejar por titulo o numero
                $tc = $temasC->firstWhere('titulo', $tb->titulo);
                if (!$tc && property_exists($tb, 'numero')) {
                    $tc = $temasC->firstWhere('numero', $tb->numero);
                }
                if (!$tc) continue;

                // Copiar planificaciones_personales
                $planesB = DB::table("$dbBackup.planificaciones_personales")->where('tema_id', $tb->id)->get();
                foreach ($planesB as $pb) {
                    $arr = (array)$pb; unset($arr['id']);
                    $arr['tema_id'] = $tc->id;
                    $exists = DB::table("$dbCurrent.planificaciones_personales")
                        ->where('tema_id', $tc->id)->exists();
                    if (!$exists) {
                        DB::table("$dbCurrent.planificaciones_personales")->insert($arr);
                        $stats['planificaciones']++;
                    }
                }

                // Copiar logros_esperados y sus indicadores
                $logrosB = DB::table("$dbBackup.logros_esperados")->where('tema_id', $tb->id)->get();
                foreach ($logrosB as $lb) {
                    $arrL = (array)$lb; unset($arrL['id']);
                    $arrL['tema_id'] = $tc->id;
                    $logroIdC = DB::table("$dbCurrent.logros_esperados")
                        ->where('tema_id', $tc->id)->where('descripcion', $arrL['descripcion'])->value('id');
                    
                    if (!$logroIdC) {
                        $logroIdC = DB::table("$dbCurrent.logros_esperados")->insertGetId($arrL);
                        $stats['logros']++;
                    }

                    // Indicadores
                    $indsB = DB::table("$dbBackup.indicadores")->where('logro_esperado_id', $lb->id)->get();
                    foreach ($indsB as $ib) {
                        $arrI = (array)$ib; unset($arrI['id']);
                        $arrI['logro_esperado_id'] = $logroIdC;
                        $existsI = DB::table("$dbCurrent.indicadores")
                            ->where('logro_esperado_id', $logroIdC)->where('descripcion', $arrI['descripcion'])->exists();
                        if (!$existsI) {
                            DB::table("$dbCurrent.indicadores")->insert($arrI);
                            $stats['indicadores']++;
                        }
                    }
                }
            }
        }

        // --- 3. Árbol de Seguimientos (vinculado a cronograma y grupo) ---
        $gruposC = DB::table("$dbCurrent.grupos")->where('asignatura_id', $asigC->id)->get();
        $gruposB = DB::table("$dbBackup.grupos")->where('asignatura_id', $asigB->id)->get();

        foreach ($gruposB as $gb) {
            // Find Docente in current
            $docB = DB::table("$dbBackup.docentes")->where('id', $gb->docente_id)->first();
            if (!$docB) continue;
            $docC = DB::table("$dbCurrent.docentes")->where('ci', $docB->ci)->first();
            if (!$docC) continue;

            $gc = $gruposC->where('sede_id', $gb->sede_id)
                          ->where('gestion', $gb->gestion)
                          ->where('docente_id', $docC->id)
                          ->firstWhere('nombre', $gb->nombre);
            
            if (!$gc) continue;

            $cronosB = DB::table("$dbBackup.cronogramas")->where('grupo_id', $gb->id)->get();
            $cronosC = DB::table("$dbCurrent.cronogramas")->where('grupo_id', $gc->id)->get();

            foreach ($cronosB as $cb) {
                // Match by numero_sesion and fecha
                $cc = $cronosC->where('numero_sesion', $cb->numero_sesion)->firstWhere('fecha', $cb->fecha);
                if (!$cc) continue;

                $segsB = DB::table("$dbBackup.seguimientos")->where('cronograma_id', $cb->id)->get();
                foreach ($segsB as $sb) {
                    $arrS = (array)$sb; unset($arrS['id']);
                    $arrS['cronograma_id'] = $cc->id;
                    $arrS['grupo_id'] = $gc->id;

                    // Match user (just in case IDs differ)
                    $userB = DB::table("$dbBackup.users")->where('id', $sb->user_id)->first();
                    if ($userB) {
                        $userC = DB::table("$dbCurrent.users")->where('ci', $userB->ci)->orWhere('username', $userB->username)->first();
                        if ($userC) $arrS['user_id'] = $userC->id;
                    }

                    $existsS = DB::table("$dbCurrent.seguimientos")->where('cronograma_id', $cc->id)->exists();
                    if (!$existsS) {
                        DB::table("$dbCurrent.seguimientos")->insert($arrS);
                        $stats['seguimientos']++;
                    }
                }
            }
        }
        
        DB::commit();

    } catch (\Exception $e) {
        DB::rollBack();
        echo "Error profundo en [{$asigC->codigo}] {$asigC->nombre}:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n-----------------\n";
        $stats['errores']++;
    }
}

echo "\nResumen Restauración Profunda:\n";
echo "- Asignaturas textos restaurados: {$stats['asignaturas_textos']}\n";
echo "- Planificaciones personales: {$stats['planificaciones']}\n";
echo "- Logros esperados: {$stats['logros']}\n";
echo "- Indicadores: {$stats['indicadores']}\n";
echo "- Seguimientos cronograma: {$stats['seguimientos']}\n";
echo "- Errores: {$stats['errores']}\n";
echo "=== PROCESO COMPLETADO ===\n";
