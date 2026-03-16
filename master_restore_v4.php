<?php
/**
 * MASTER_RESTORE_v4.php
 * ─────────────────────────────────────────────────────────────
 * v4: Ultra Verbose & Robust (Fix plan_estudios & Match by CI)
 * ─────────────────────────────────────────────────────────────
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';
$ciTania = '4534773';

echo "=== INICIANDO MASTER RESTORE v4 ===\n";

// --- PASO 1: MERGE (Breve) ---
echo "\n[1/3] Unificando asignaturas...\n";
// (Misma lógica simplificada)
$scopedAsigs = DB::table('asignaturas')->where(fn($q) => $q->where('codigo', 'LIKE', '%-%-%')->orWhere('codigo', 'REGEXP', '-[A-Z][A-Z]+$'))->whereNull('deleted_at')->get();
foreach ($scopedAsigs as $scoped) {
    if (!preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $scoped->codigo, $m)) continue;
    $codigoBase = $m[1];
    $oficial = DB::table('asignaturas')->where('codigo', $codigoBase)->where('id', '!=', $scoped->id)->whereNull('deleted_at')->first();
    if (!$oficial) continue;
    echo "  Migrando: {$scoped->codigo} -> {$oficial->codigo}\n";
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
echo "\n[2/3] Restaurando desde Backup (v4)...\n";
$gruposBackup = DB::table("$dbBackup.grupos as g")
    ->join("$dbBackup.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
    ->whereNotNull('g.docente_id')
    ->select(['g.*', 'a.codigo as asig_backup_codigo', 'a.nombre as asig_backup_nombre', 'a.plan_estudios as asig_backup_plan'])
    ->get();

$stats = ['restaurados' => 0, 'recreados' => 0, 'saltados' => 0];

foreach ($gruposBackup as $gb) {
    $isTania = false;
    
    // 2.1 Encontrar al Docente en Backup
    $gbDocente = DB::table("$dbBackup.docentes")->where('id', $gb->docente_id)->first();
    $ciMatch = $gbDocente ? $gbDocente->ci : null;
    
    if ($ciMatch == $ciTania) {
        $isTania = true;
        echo "\n[DEBUG TANIA] Procesando materia backup: {$gb->asig_backup_codigo} (ID Backup: {$gb->id})\n";
    }

    if (!$ciMatch || $ciMatch == '0') {
        $stats['saltados']++;
        continue;
    }

    // 2.2 Encontrar al Docente en Actual
    $docenteActual = DB::table("$dbCurrent.docentes")->where('ci', $ciMatch)->first();
    if (!$docenteActual) {
        if ($isTania) echo "  [DEBUG TANIA] ❌ Docente no encontrado en Current por CI $ciMatch\n";
        $stats['saltados']++;
        continue;
    }
    $docenteActualId = $docenteActual->id;

    // 2.3 Encontrar Asignatura Elegida
    $codigoBase = $gb->asig_backup_codigo;
    if (preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $gb->asig_backup_codigo, $m)) $codigoBase = $m[1];
    
    $asignaturaElegida = DB::table("$dbCurrent.asignaturas")->where('codigo', $codigoBase)->whereNull('deleted_at')->first();
    if (!$asignaturaElegida) {
        if ($isTania) echo "  [DEBUG TANIA] ❌ Asignatura $codigoBase no encontrada en Current\n";
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
        if ($isTania) echo "  [DEBUG TANIA] 🆕 Recreando grupo '{$gb->nombre}'...\n";
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
            
            // Link Carrera si no existe (v4 asegura visibilidad)
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
        } catch (\Exception $e) {
            if ($isTania) echo "  [DEBUG TANIA] ❌ Error recreando: " . $e->getMessage() . "\n";
            continue;
        }
    } else {
        $grupoActualId = $grupoActual->id;
        if (empty($grupoActual->docente_id) || $grupoActual->docente_id != $docenteActualId) {
            DB::table("$dbCurrent.grupos")->where('id', $grupoActualId)->update(['docente_id' => $docenteActualId]);
            if ($isTania) echo "  [DEBUG TANIA] ✅ Vínculo de docente actualizado en grupo existente.\n";
        }
        $stats['restaurados']++;
    }

    // 2.5 Restaurar subordinados (Try-catch silencioso)
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
                if ($table == 'horarios' && $item->id_horario_api) $query->where('id_horario_api', $item->id_horario_api);
                else {
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

echo "\nResumen Restauración: Recreados: {$stats['recreados']}, Restaurados (Vínculo): {$stats['restaurados']}, Saltados: {$stats['saltados']}\n";
echo "=== PROCESO COMPLETADO v4 ===\n";
