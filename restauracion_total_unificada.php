<?php
/**
 * RESTAURACIÓN TOTAL UNIFICADA
 * Este archivo contiene todos los pasos de restauración consolidados.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

echo "\n=======================================================\n";
echo "   INICIANDO RESTAURACIÓN TOTAL DEL SISTEMA SIDOPA\n";
echo "=======================================================\n\n";

echo "\n>>> Paso 1: Restauración de Asignaturas y Preservación de Nombres <<<\n";
call_user_func(function() use ($dbCurrent, $dbBackup) {

/**
 * MASTER_RESTORE_v7.php
 * ─────────────────────────────────────────────────────────────
 * v7: Preservación de Nombres (Multi-Plan / Multi-Campus)
 * ─────────────────────────────────────────────────────────────
 * Si una sede tiene una asignatura con el mismo código pero un
 * nombre diferente, la conserva como una fila separada en la 
 * tabla de asignaturas (limpiando el código -COC- a su versión oficial)
 * en lugar de intentar forzarla (merge) hacia la materia oficial de
 * otra sede (ej: PROBABILIDAD vs PROGRAMACION).
 * ─────────────────────────────────────────────────────────────
 */










echo "=== INICIANDO MASTER RESTORE v7 (Preservación de Nombres) ===\n";

// --- PASO 1: MERGE (Inteligente por Nombre) ---
echo "\n[1/3] Limpiando códigos Scoped (Deduplicación Segura)...\n";
$scopedAsigs = DB::table('asignaturas')->where(fn($q) => $q->where('codigo', 'LIKE', '%-%-%')->orWhere('codigo', 'REGEXP', '-[A-Z][A-Z]+$'))->whereNull('deleted_at')->get();

foreach ($scopedAsigs as $scoped) {
    if (!preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $scoped->codigo, $m)) continue;
    $codigoBase = $m[1];
    $plan = $scoped->plan_estudios ?: 'N';
    
    // Buscar si HAY una oficial EXACTA en código y MUY SIMILAR en nombre
    $candidatosExactos = DB::table('asignaturas')->where('codigo', $codigoBase)->where('id', '!=', $scoped->id)->whereNull('deleted_at')->get();
    
    $oficialMerge = null;
    $bestScore = -1;
    foreach ($candidatosExactos as $cand) {
        similar_text(strtoupper($scoped->nombre), strtoupper($cand->nombre), $percent);
        if ($percent > $bestScore) {
            $bestScore = $percent;
            $oficialMerge = $cand;
        }
    }

    DB::beginTransaction();
    try {
        if ($oficialMerge && $bestScore > 80) {
            // MERGE NORMAL: Nombres iguales o casi iguales, podemos unificar.
            echo "  [MERGE] {$scoped->codigo} -> {$oficialMerge->codigo} ({$oficialMerge->nombre})\n";
            DB::table('asignatura_carrera')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficialMerge->id]);
            DB::table('unidades')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficialMerge->id]);
            DB::table('grupos')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficialMerge->id]);
            DB::table('asignaturas')->where('id', $scoped->id)->delete();
        } else {
            // RENOMBRAR CONSERVANDO: Nombres diferentes (ej: Probabilidad vs Programacion)
            // Simplemente le quitamos el "-COC-..." al código pero lo dejamos vivir como nueva asignatura oficial.
            echo "  [CONSERVAR] Mismo Código, Diferente Nombre. Renombrando a {$codigoBase}: ({$scoped->nombre})\n";
            DB::table('asignaturas')->where('id', $scoped->id)->update(['codigo' => $codigoBase]);
        }
        DB::commit();
    } catch (\Exception $e) { 
        DB::rollBack(); 
        echo "   -> Error procesando {$scoped->codigo}: " . $e->getMessage() . "\n";
    }
}

// --- PASO 2: RESTORE ---
echo "\n[2/3] Restaurando desde Backup (v7)...\n";
$gruposBackup = DB::table("$dbBackup.grupos as g")
    ->join("$dbBackup.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
    ->whereNotNull('g.docente_id')
    ->select(['g.*', 'a.codigo as asig_backup_codigo', 'a.nombre as asig_backup_nombre', 'a.plan_estudios as asig_backup_plan'])
    ->get();

$stats = ['restaurados' => 0, 'recreados' => 0, 'saltados' => 0];

foreach ($gruposBackup as $gb) {
    // 2.1 Encontrar al Docente
    $gbDocente = DB::table("$dbBackup.docentes")->where('id', $gb->docente_id)->first();
    $ciMatch = $gbDocente ? $gbDocente->ci : null;
    if (!$ciMatch || $ciMatch == '0') { $stats['saltados']++; continue; }

    $docenteActual = DB::table("$dbCurrent.docentes")->where('ci', $ciMatch)->first();
    if (!$docenteActual) { $stats['saltados']++; continue; }
    $docenteActualId = $docenteActual->id;

    // 2.2 Encontrar Asignatura Elegida (Por Código MÁS SIMILITUD EXACTA)
    $codigoBase = $gb->asig_backup_codigo;
    if (preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $gb->asig_backup_codigo, $m)) $codigoBase = $m[1];
    
    // Ahora podemos tener VARIAS asignaturas con el mismo código (ej: dos ELC-213)
    // Elegimos la que tenga el nombre MÁS PARECIDO al backup.
    $candidatos = DB::table("$dbCurrent.asignaturas")->where('codigo', $codigoBase)->whereNull('deleted_at')->get();
    
    $asignaturaElegida = null;
    $bestScore = -1;
    foreach ($candidatos as $cand) {
        similar_text(strtoupper($gb->asig_backup_nombre), strtoupper($cand->nombre), $percent);
        if ($percent > $bestScore) {
            $bestScore = $percent;
            $asignaturaElegida = $cand;
        }
    }

    // SI NO HAY NINGUNA ASIGNATURA CON EL MISMO NOMBRE (Similitud < 80%)
    // CREAMOS UNA NUEVA ASIGNATURA CON EL CÓDIGO Y NOMBRE EXACTO DEL BACKUP.
    // Esto preserva nombres específicos como "PROBABILIDAD Y ESTADISTICA" para ELC-213 Cochabamba.
    if (!$asignaturaElegida || $bestScore < 80) {
        try {
            $newAsigId = DB::table("$dbCurrent.asignaturas")->insertGetId([
                'codigo' => $codigoBase,
                'nombre' => $gb->asig_backup_nombre,
                'plan_estudios' => $gb->asig_backup_plan ?: 'N',
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $asignaturaElegida = DB::table("$dbCurrent.asignaturas")->where('id', $newAsigId)->first();
            echo "  [CREADA ASIGNATURA] Código: {$codigoBase}, Nombre: {$gb->asig_backup_nombre}\n";
        } catch (\Exception $e) {
            echo "  [ERROR CREANDO ASIGNATURA] " . $e->getMessage() . "\n";
            $stats['saltados']++;
            continue;
        }
    }

    // 2.3 Buscar/Recrear Grupo
    $grupoActual = DB::table("$dbCurrent.grupos")
        ->where('asignatura_id', $asignaturaElegida->id)
        ->where('gestion', $gb->gestion)
        ->where('sede_id', $gb->sede_id)
        ->where('nombre', $gb->nombre)
        ->first();

    $grupoActualId = null;

    if (!$grupoActual) {
        try {
            $grupoActualId = DB::table("$dbCurrent.grupos")->insertGetId([
                'gestion'       => $gb->gestion,
                'asignatura_id' => $asignaturaElegida->id,
                'carrera_id'    => $gb->carrera_id,
                'nombre'        => $gb->nombre,
                'tipo'          => $gb->tipo ?? 'TEORICO',
                'sede_id'       => $gb->sede_id,
                'docente_id'    => $docenteActualId,
                'estado'        => 'ACTIVO',
                'created_at'    => now(),
                'updated_at'    => now()
            ]);
            $stats['recreados']++;
            
            $linkExists = DB::table("$dbCurrent.asignatura_carrera")->where('asignatura_id', $asignaturaElegida->id)->where('carrera_id', $gb->carrera_id)->where('sede_id', $gb->sede_id)->exists();
            if (!$linkExists) {
                DB::table("$dbCurrent.asignatura_carrera")->insert([
                    'asignatura_id' => $asignaturaElegida->id,
                    'carrera_id'    => $gb->carrera_id,
                    'sede_id'       => $gb->sede_id,
                    'semestre'      => 0,
                    'created_at'    => now(),
                    'updated_at'    => now()
                ]);
            }
        } catch (\Exception $e) { continue; }
    } else {
        $grupoActualId = $grupoActual->id;
        if (empty($grupoActual->docente_id) || $grupoActual->docente_id != $docenteActualId) {
            DB::table("$dbCurrent.grupos")->where('id', $grupoActualId)->update(['docente_id' => $docenteActualId]);
        }
        $stats['restaurados']++;
    }

    // 2.4 Restaurar subordinados
    if ($grupoActualId) {
        $subordinados = [
            'cronogramas' => ['grupo_id', 'fecha', 'numero_sesion'],
            'horarios'    => ['id_horario_api'],
            'seguimientos'=> ['grupo_id', 'fecha', 'tema_cumplido']
        ];
        foreach ($subordinados as $table => $uniqueFields) {
            $dataBackup = DB::table("$dbBackup.$table")->where('grupo_id', $gb->id)->get();
            foreach ($dataBackup as $item) {
                $query = DB::table("$dbCurrent.$table")->where('grupo_id', $grupoActualId);
                if ($table == 'horarios' && property_exists($item, 'id_horario_api') && $item->id_horario_api) {
                    $query->where('id_horario_api', $item->id_horario_api);
                } else {
                    foreach ($uniqueFields as $f) { if ($f != 'grupo_id') $query->where($f, $item->$f); }
                }
                if (!$query->exists()) {
                    try {
                        $arr = (array)$item; unset($arr['id']); 
                        $arr['grupo_id'] = $grupoActualId;
                        if ($table == 'cronogramas') $arr['asignatura_id'] = $asignaturaElegida->id;
                        DB::table("$dbCurrent.$table")->insert($arr);
                    } catch (\Exception $e) {}
                }
            }
        }
    }
}

echo "\nResumen Restauración v7: Recreados: {$stats['recreados']}, Restaurados: {$stats['restaurados']}, Saltados: {$stats['saltados']}\n";
echo "=== PROCESO COMPLETADO v7 ===\n";

});

echo "\n>>> Paso 2: Restauración de Pedagogía Base (0%) <<<\n";
call_user_func(function() use ($dbCurrent, $dbBackup) {

/**
 * RESTORE_PEDAGOGY.php
 * ─────────────────────────────────────────────────────────────
 * Restaura el contenido pedagógico (unidades, temas,
 * bibliografias, evaluaciones) desde el backup para las 
 * asignaturas que carecen de ellas (ej. aquellas recreadas por v7).
 * ─────────────────────────────────────────────────────────────
 */










echo "=== INICIANDO RESTAURACIÓN DE CONTENIDO PEDAGÓGICO ===\n";

// Get all backup asignaturas that actually HAVE groups
$backupAsigs = DB::table("$dbBackup.asignaturas as a")
    ->join("$dbBackup.grupos as g", 'g.asignatura_id', '=', 'a.id')
    ->select('a.*')
    ->distinct()
    ->get();

$stats = ['unidades' => 0, 'temas' => 0, 'evaluaciones' => 0, 'bibliografias' => 0, 'asignaturas_procesadas' => 0];

foreach ($backupAsigs as $bAsig) {
    if (!preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $bAsig->codigo, $m)) continue;
    $codigoBase = $m[1];
    $planBasico = $bAsig->plan_estudios ?: 'N';

    $candidatos = DB::table("$dbCurrent.asignaturas")
        ->where('codigo', $codigoBase)
        ->whereNull('deleted_at')
        ->get();
        
    $oficialId = null;
    $bestScore = -1;
    
    // Primero buscar coincidencia exacta de nombre
    foreach ($candidatos as $cand) {
        if (strtoupper($cand->nombre) === strtoupper($bAsig->nombre) && ($cand->plan_estudios ?: 'N') === $planBasico) {
            $oficialId = $cand->id;
            break;
        }
    }
    
    // Si no es exacta, buscar por similitud alta (si hubo merge)
    if (!$oficialId) {
        foreach ($candidatos as $cand) {
            similar_text(strtoupper($bAsig->nombre), strtoupper($cand->nombre), $percent);
            if ($percent > 80 && ($cand->plan_estudios ?: 'N') === $planBasico) {
                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $oficialId = $cand->id;
                }
            }
        }
    }
    
    // Todavía sin match? relajar plan_estudios pero similitud muy alta > 90
    if (!$oficialId) {
        foreach ($candidatos as $cand) {
            similar_text(strtoupper($bAsig->nombre), strtoupper($cand->nombre), $percent);
            if ($percent > 90) {
                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $oficialId = $cand->id;
                }
            }
        }
    }

    if (!$oficialId) continue;

    // Verificar si la asignatura oficial ya tiene unidades.
    $tieneUnidades = DB::table("$dbCurrent.unidades")->where('asignatura_id', $oficialId)->exists();
    if ($tieneUnidades) continue; // Ya tiene contenido, no copiamos para no duplicar

    $copiadoAlgo = false;

    DB::beginTransaction();
    try {
        // --- COPIAR BIBLIOGRAFIAS ---
        $biblios = DB::table("$dbBackup.bibliografias")->where('asignatura_id', $bAsig->id)->get();
        foreach ($biblios as $bib) {
            $arr = (array)$bib; unset($arr['id']);
            $arr['asignatura_id'] = $oficialId;
            $exists = DB::table("$dbCurrent.bibliografias")->where('asignatura_id', $oficialId)->where('titulo', $arr['titulo'])->exists();
            if (!$exists) {
                DB::table("$dbCurrent.bibliografias")->insert($arr);
                $stats['bibliografias']++;
                $copiadoAlgo = true;
            }
        }

        // --- COPIAR EVALUACIONES ---
        $evals = DB::table("$dbBackup.evaluaciones")->where('asignatura_id', $bAsig->id)->get();
        foreach ($evals as $ev) {
            $arr = (array)$ev; unset($arr['id']);
            $arr['asignatura_id'] = $oficialId;
            $exists = DB::table("$dbCurrent.evaluaciones")->where('asignatura_id', $oficialId)->where('nombre', $arr['nombre'])->exists();
            if (!$exists) {
                DB::table("$dbCurrent.evaluaciones")->insert($arr);
                $stats['evaluaciones']++;
                $copiadoAlgo = true;
            }
        }

        // --- COPIAR UNIDADES Y TEMAS ---
        $unidades = DB::table("$dbBackup.unidades")->where('asignatura_id', $bAsig->id)->get();
        foreach ($unidades as $u) {
            $arrU = (array)$u; 
            $oldUId = $arrU['id'];
            unset($arrU['id']);
            $arrU['asignatura_id'] = $oficialId;
            
            $exists = DB::table("$dbCurrent.unidades")->where('asignatura_id', $oficialId)->where('titulo', $arrU['titulo'])->first();
            if (!$exists) {
                $newUId = DB::table("$dbCurrent.unidades")->insertGetId($arrU);
                $stats['unidades']++;
                $copiadoAlgo = true;
                
                $temas = DB::table("$dbBackup.temas")->where('unidad_id', $oldUId)->get();
                foreach ($temas as $t) {
                    $arrT = (array)$t; unset($arrT['id']);
                    $arrT['unidad_id'] = $newUId;
                    DB::table("$dbCurrent.temas")->insert($arrT);
                    $stats['temas']++;
                }
            }
        }

        DB::commit();
        if ($copiadoAlgo) {
            $stats['asignaturas_procesadas']++;
            echo "Restaurado contenido para: [{$codigoBase}] {$bAsig->nombre}\n";
        }
    } catch (\Exception $e) {
        DB::rollBack();
        echo "Error restaurando contenido para [{$codigoBase}] {$bAsig->nombre}: " . $e->getMessage() . "\n";
    }
}

echo "\nResumen Restauración Contenido Pedagógico:\n";
echo "- Asignaturas enriquecidas: {$stats['asignaturas_procesadas']}\n";
echo "- Unidades restauradas: {$stats['unidades']}\n";
echo "- Temas restaurados: {$stats['temas']}\n";
echo "- Evaluaciones restauradas: {$stats['evaluaciones']}\n";
echo "- Bibliografías restauradas: {$stats['bibliografias']}\n";
echo "=== PROCESO COMPLETADO PASO 2 ===\n";

});

echo "\n>>> Paso 3: Restauración de Cronogramas Faltantes (Agresivo + Docentes) <<<\n";
call_user_func(function() use ($dbCurrent, $dbBackup, &$restorationLog) {

echo "=== INICIANDO COSECHA DE CRONOGRAMAS ===\n";

$recreatedAsigs = DB::table("$dbCurrent.asignaturas")->get();

$totalRestaurados = 0;
$errores = 0;
// Initialize restorationLog if it's not already set by a previous step
if (!isset($restorationLog)) {
    $restorationLog = [];
}

foreach ($recreatedAsigs as $asigC) {
    // 1. Encontrar candidatos en backup (Cosecha Múltiple Inteligente)
    $planC = $asigC->plan_estudios ?: 'N';
    
    // Extraer raíz del código (ej: ENF-115 de ENF-115-COC)
    $rootCode = $asigC->codigo;
    if (preg_match('/^([A-Z]+-[0-9]+)/', $asigC->codigo, $m)) {
        $rootCode = $m[1];
    }

    $candidatosB = DB::table("$dbBackup.asignaturas")->where('codigo', 'LIKE', $rootCode . '%')->get();
    
    // Fallback: Si no hay candidatos por código, buscar por nombre en TODO el backup
    if ($candidatosB->isEmpty()) {
        $candidatosB = DB::table("$dbBackup.asignaturas")
            ->where('nombre', 'LIKE', '%' . substr($asigC->nombre, 0, 10) . '%')
            ->get();
    }

    $matchesB = [];
    foreach ($candidatosB as $cand) {
        $planB = $cand->plan_estudios ?: 'N';
        similar_text(strtoupper($asigC->nombre), strtoupper($cand->nombre), $percent);
        
        // Match si el plan coincide O si el nombre es casi idéntico (>95%)
        // Pero siempre con un mínimo de 70% de similitud de nombre para evitar falsos positivos
        if (($planB === $planC || $percent > 95) && $percent > 70) {
            $matchesB[] = $cand;
        }
    }
    
    if (empty($matchesB)) continue;

    // Registrar docente para el reporte
    $docenteName = "SIN DOCENTE";
    $docenteCI = "N/A";
    $grupoInfo = DB::table("$dbCurrent.grupos")->where('asignatura_id', $asigC->id)->first();
    if ($grupoInfo && $grupoInfo->docente_id) {
        $doc = DB::table("$dbCurrent.docentes")->where('id', $grupoInfo->docente_id)->first();
        if ($doc) {
            $docenteName = $doc->nombre_completo;
            $docenteCI = $doc->ci;
        }
    }

    $countThisAsig = 0;

    foreach ($matchesB as $asigB) {
        $cronosB = DB::table("$dbBackup.cronogramas")->where('asignatura_id', $asigB->id)->get();
        if ($cronosB->count() > 0) {
            $gruposCurrent = DB::table("$dbCurrent.grupos")->where('asignatura_id', $asigC->id)->get();
            if ($gruposCurrent->count() > 0) {
                foreach ($cronosB as $cb) {
                    $gbOriginal = DB::table("$dbBackup.grupos")->where('id', $cb->grupo_id)->first();
                    $grupoDestino = $gbOriginal ? ($gruposCurrent->firstWhere('nombre', $gbOriginal->nombre) ?? $gruposCurrent->first()) : $gruposCurrent->first();
                    
                    if ($grupoDestino) {
                        $arr = (array)$cb; unset($arr['id']);
                        $arr['grupo_id'] = $grupoDestino->id;
                        $arr['asignatura_id'] = $asigC->id;

                        $exists = DB::table("$dbCurrent.cronogramas")
                            ->where('grupo_id', $grupoDestino->id)
                            ->where('numero_sesion', $arr['numero_sesion'])
                            ->where('fecha', $arr['fecha'])
                            ->exists();

                        if (!$exists) {
                            try {
                                DB::table("$dbCurrent.cronogramas")->insert($arr);
                                $totalRestaurados++;
                                $countThisAsig++;
                            } catch (\Exception $e) {
                                $errores++;
                            }
                        }
                    }
                }
            }
        }
    }

    if ($countThisAsig > 0) {
        $restorationLog[$asigC->id] = [
            'codigo' => $asigC->codigo,
            'nombre' => $asigC->nombre,
            'docente' => $docenteName,
            'ci' => $docenteCI,
            'cronogramas' => $countThisAsig,
            'pedagogia' => 0
        ];
    }
}

echo "Total cronogramas restaurados: $totalRestaurados\n";

});

echo "\n>>> Paso 4: Restauración Pedagógica Profunda (Agresivo) <<<\n";
call_user_func(function() use ($dbCurrent, $dbBackup, &$restorationLog) {

echo "=== INICIANDO COSECHA DE TEXTOS E INDICADORES ===\n";

$recreatedAsigs = DB::table("$dbCurrent.asignaturas")->get();

$stats = ['asignaturas_textos' => 0, 'planificaciones' => 0, 'logros' => 0, 'indicadores' => 0];

foreach ($recreatedAsigs as $asigC) {
    $planC = $asigC->plan_estudios ?: 'N';
    $rootCode = $asigC->codigo;
    if (preg_match('/^([A-Z]+-[0-9]+)/', $asigC->codigo, $m)) $rootCode = $m[1];

    $candidatosB = DB::table("$dbBackup.asignaturas")->where('codigo', 'LIKE', $rootCode . '%')->get();
    if ($candidatosB->isEmpty()) {
        $candidatosB = DB::table("$dbBackup.asignaturas")->where('nombre', 'LIKE', '%' . substr($asigC->nombre, 0, 10) . '%')->get();
    }

    $matchesB = [];
    foreach ($candidatosB as $cand) {
        similar_text(strtoupper($asigC->nombre), strtoupper($cand->nombre), $percent);
        if ((($cand->plan_estudios ?: 'N') === $planC || $percent > 95) && $percent > 70) {
            $matchesB[] = $cand;
        }
    }
    
    if (empty($matchesB)) continue;

    $pedagogyCount = 0;

    foreach ($matchesB as $asigB) {
        DB::beginTransaction();
        try {
            // 1. Textos
            if (empty($asigC->justificacion) && !empty($asigB->justificacion)) {
                DB::table("$dbCurrent.asignaturas")->where('id', $asigC->id)->update([
                    'justificacion' => $asigB->justificacion,
                    'proposito_general' => $asigB->proposito_general,
                    'metodologia_general' => $asigB->metodologia_general,
                    'competencia_asignatura' => $asigB->competencia_asignatura ?? $asigB->objetivo_general ?? null
                ]);
                $stats['asignaturas_textos']++;
                $pedagogyCount++;
            }

            // 2. Planificaciones/Logros
            $unidadesB = DB::table("$dbBackup.unidades")->where('asignatura_id', $asigB->id)->get();
            $unidadesC = DB::table("$dbCurrent.unidades")->where('asignatura_id', $asigC->id)->get();

            foreach ($unidadesB as $ub) {
                $uc = $unidadesC->firstWhere('titulo', $ub->titulo);
                if (!$uc) continue;

                $temasB = DB::table("$dbBackup.temas")->where('unidad_id', $ub->id)->get();
                $temasC = DB::table("$dbCurrent.temas")->where('unidad_id', $uc->id)->get();

                foreach ($temasB as $tb) {
                    $tc = $temasC->firstWhere('titulo', $tb->titulo);
                    if (!$tc) continue;

                    // Planificaciones
                    $planesB = DB::table("$dbBackup.planificaciones_personales")->where('tema_id', $tb->id)->get();
                    foreach ($planesB as $pb) {
                        if (!DB::table("$dbCurrent.planificaciones_personales")->where('tema_id', $tc->id)->exists()) {
                            $arr = (array)$pb; unset($arr['id']); $arr['tema_id'] = $tc->id;
                            DB::table("$dbCurrent.planificaciones_personales")->insert($arr);
                            $stats['planificaciones']++;
                            $pedagogyCount++;
                        }
                    }

                    // Logros/Indicadores
                    $logrosB = DB::table("$dbBackup.logros_esperados")->where('tema_id', $tb->id)->get();
                    foreach ($logrosB as $lb) {
                        $logroIdC = DB::table("$dbCurrent.logros_esperados")->where('tema_id', $tc->id)->where('descripcion', $lb->descripcion)->value('id');
                        if (!$logroIdC) {
                            $arrL = (array)$lb; unset($arrL['id']); $arrL['tema_id'] = $tc->id;
                            $logroIdC = DB::table("$dbCurrent.logros_esperados")->insertGetId($arrL);
                            $stats['logros']++;
                            $pedagogyCount++;
                        }
                        $indsB = DB::table("$dbBackup.indicadores")->where('logro_esperado_id', $lb->id)->get();
                        foreach ($indsB as $ib) {
                            if (!DB::table("$dbCurrent.indicadores")->where('logro_esperado_id', $logroIdC)->where('descripcion', $ib->descripcion)->exists()) {
                                $arrI = (array)$ib; unset($arrI['id']); $arrI['logro_esperado_id'] = $logroIdC;
                                DB::table("$dbCurrent.indicadores")->insert($arrI);
                                $stats['indicadores']++;
                                $pedagogyCount++;
                            }
                        }
                    }
                }
            }
            DB::commit();
        } catch (\Exception $e) { DB::rollBack(); }
    }

    if ($pedagogyCount > 0) {
        if (!isset($restorationLog[$asigC->id])) {
            $docenteName = "SIN DOCENTE"; $docenteCI = "N/A";
            $grupoInfo = DB::table("$dbCurrent.grupos")->where('asignatura_id', $asigC->id)->first();
            if ($grupoInfo && $grupoInfo->docente_id) {
                $doc = DB::table("$dbCurrent.docentes")->where('id', $grupoInfo->docente_id)->first();
                if ($doc) { $docenteName = $doc->nombre_completo; $docenteCI = $doc->ci; }
            }
            $restorationLog[$asigC->id] = ['codigo' => $asigC->codigo, 'nombre' => $asigC->nombre, 'docente' => $docenteName, 'ci' => $docenteCI, 'cronogramas' => 0, 'pedagogia' => 0];
        }
        $restorationLog[$asigC->id]['pedagogia'] += $pedagogyCount;
    }
}

echo "\n=======================================================\n";
echo "   REPORTE DE MATERIAS RESTAURADAS\n";
echo "=======================================================\n";
printf("%-15s | %-30s | %-30s | %-10s | %-5s | %-5s\n", "CODIGO", "NOMBRE", "DOCENTE", "CI", "CRON", "PEDA");
echo str_repeat("-", 110) . "\n";
foreach ($restorationLog as $log) {
    printf("%-15s | %-30.30s | %-30.30s | %-10s | %-5d | %-5d\n", 
        $log['codigo'], $log['nombre'], $log['docente'], $log['ci'], $log['cronogramas'], $log['pedagogia']);
}

echo "\nResumen Restauración Profunda:\n";
echo "- Asignaturas textos restaurados: {$stats['asignaturas_textos']}\n";
echo "- Planificaciones personales: {$stats['planificaciones']}\n";
echo "- Logros esperados: {$stats['logros']}\n";
echo "- Indicadores: {$stats['indicadores']}\n";
echo "=======================================================\n";

});

echo "\n=======================================================\n";
echo "   RESTAURACIÓN TOTAL FINALIZADA CON ÉXITO\n";
echo "=======================================================\n";

