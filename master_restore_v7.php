<?php
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

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

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
