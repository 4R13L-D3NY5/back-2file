<?php
/**
 * MASTER_RESTORE_v5.php
 * ─────────────────────────────────────────────────────────────
 * v5: Ultra Refined Matching (Similarity + Plan Matching)
 * ─────────────────────────────────────────────────────────────
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

echo "=== INICIANDO MASTER RESTORE v5 (Matching Refinado) ===\n";

// --- PASO 1: MERGE (Breve) ---
echo "\n[1/3] Unificando asignaturas...\n";
$scopedAsigs = DB::table('asignaturas')->where(fn($q) => $q->where('codigo', 'LIKE', '%-%-%')->orWhere('codigo', 'REGEXP', '-[A-Z][A-Z]+$'))->whereNull('deleted_at')->get();
foreach ($scopedAsigs as $scoped) {
    if (!preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $scoped->codigo, $m)) continue;
    $codigoBase = $m[1];
    $oficial = DB::table('asignaturas')->where('codigo', $codigoBase)->where('id', '!=', $scoped->id)->whereNull('deleted_at')->first();
    if (!$oficial) continue;
    
    DB::beginTransaction();
    try {
        DB::table('asignatura_carrera')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficial->id]);
        DB::table('unidades')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficial->id]);
        DB::table('grupos')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficial->id]);
        DB::table('asignaturas')->where('id', $scoped->id)->delete();
        DB::commit();
    } catch (\Exception $e) { DB::rollBack(); }
}

// --- PASO 2: RESTORE ---
echo "\n[2/3] Restaurando desde Backup (v5)...\n";
$gruposBackup = DB::table("$dbBackup.grupos as g")
    ->join("$dbBackup.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
    ->whereNotNull('g.docente_id')
    ->select(['g.*', 'a.codigo as asig_backup_codigo', 'a.nombre as asig_backup_nombre', 'a.plan_estudios as asig_backup_plan'])
    ->get();

$stats = ['restaurados' => 0, 'recreados' => 0, 'saltados' => 0];

foreach ($gruposBackup as $gb) {
    // 2.1 Encontrar al Docente en Backup
    $gbDocente = DB::table("$dbBackup.docentes")->where('id', $gb->docente_id)->first();
    $ciMatch = $gbDocente ? $gbDocente->ci : null;
    
    if (!$ciMatch || $ciMatch == '0') {
        $stats['saltados']++;
        continue;
    }

    // 2.2 Encontrar al Docente en Actual
    $docenteActual = DB::table("$dbCurrent.docentes")->where('ci', $ciMatch)->first();
    if (!$docenteActual) {
        $stats['saltados']++;
        continue;
    }
    $docenteActualId = $docenteActual->id;

    // 2.3 Encontrar Asignatura Elegida (Matching Refinado)
    $codigoBase = $gb->asig_backup_codigo;
    if (preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $gb->asig_backup_codigo, $m)) $codigoBase = $m[1];
    $planRespaldo = $gb->asig_backup_plan ?: 'N';
    
    $candidatos = DB::table("$dbCurrent.asignaturas")
        ->where('codigo', $codigoBase)
        ->whereNull('deleted_at')
        ->get();
        
    $asignaturaElegida = null;
    
    if ($candidatos->count() == 1) {
        $asignaturaElegida = $candidatos->first();
    } elseif ($candidatos->count() > 1) {
        $bestScore = -1;
        foreach ($candidatos as $cand) {
            similar_text(strtoupper($gb->asig_backup_nombre), strtoupper($cand->nombre), $percent);
            // Recompensa extra si el plan de estudios coincide
            if (($cand->plan_estudios ?: 'N') == $planRespaldo) {
                $percent += 20; 
            }
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $asignaturaElegida = $cand;
            }
        }
    }

    if (!$asignaturaElegida) {
        $stats['saltados']++;
        continue;
    }

    // 2.4 Buscar/Recrear Grupo
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
            
            // Link Carrera
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

    // 2.5 Restaurar subordinados
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

echo "\nResumen Restauración v5: Recreados: {$stats['recreados']}, Restaurados: {$stats['restaurados']}, Saltados: {$stats['saltados']}\n";
echo "=== PROCESO COMPLETADO v5 ===\n";
