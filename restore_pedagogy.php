<?php
/**
 * RESTORE_PEDAGOGY.php
 * ─────────────────────────────────────────────────────────────
 * Restaura el contenido pedagógico (unidades, temas,
 * bibliografias, evaluaciones) desde el backup para las 
 * asignaturas que carecen de ellas (ej. aquellas recreadas por v7).
 * ─────────────────────────────────────────────────────────────
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

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
echo "=== PROCESO COMPLETADO ===\n";
