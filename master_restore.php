<?php
/**
 * MASTER_RESTORE_CLEANUP.php
 * ─────────────────────────────────────────────────────────────
 * 1. UNIFICAR: Merge scoped a oficial (Smart Matching v5).
 * 2. RESTAURAR: Recuperar data de backup (v6 con flexibilidad).
 * 3. LIMPIAR: Eliminar asignaciones docentes que NO están en el backup.
 * ─────────────────────────────────────────────────────────────
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

echo "=== INICIANDO MASTER RESTORE & CLEANUP ===\n";

// --- PASO 1: MERGE INTELIGENTE ---
echo "\n[1/3] Unificando asignaturas (Merge v5)...\n";
$scopedAsigs = DB::table('asignaturas')
    ->where(function($q) {
        $q->where('codigo', 'LIKE', '%-%-%')
          ->orWhere('codigo', 'REGEXP', '-[A-Z][A-Z]+$');
    })
    ->whereNull('deleted_at')
    ->get();

foreach ($scopedAsigs as $scoped) {
    if (!preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $scoped->codigo, $matches)) continue;
    $codigoBase = $matches[1];
    $plan = $scoped->plan_estudios ?: 'N';
    $candidatos = DB::table('asignaturas')->where('codigo', $codigoBase)->where('id', '!=', $scoped->id)->whereNull('deleted_at')->get();
    
    $oficial = null;
    if ($candidatos->count() == 1) $oficial = $candidatos->first();
    elseif ($candidatos->count() > 1) {
        $bestScore = -1;
        foreach ($candidatos as $cand) {
            similar_text(strtoupper($scoped->nombre), strtoupper($cand->nombre), $percent);
            if (($cand->plan_estudios ?: 'N') == $plan) $percent += 10;
            if ($percent > $bestScore) { $bestScore = $percent; $oficial = $cand; }
        }
    }
    if (!$oficial) continue;

    echo "Migrando: {$scoped->codigo} -> {$oficial->codigo} (IDs: {$scoped->id} -> {$oficial->id})\n";

    DB::beginTransaction();
    try {
        // Redirigir Unidades Académicas (Manejo de duplicados uno a uno)
        $links = DB::table('asignatura_carrera')->where('asignatura_id', $scoped->id)->get();
        foreach ($links as $link) {
            try {
                DB::table('asignatura_carrera')->where('id', $link->id)->update(['asignatura_id' => $oficial->id]);
            } catch (\Exception $e) {
                // Si ya existe el link oficial, simplemente borramos el link scoped
                DB::table('asignatura_carrera')->where('id', $link->id)->delete();
            }
        }

        DB::table('unidades')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficial->id]);
        DB::table('bibliografias')->where('asignatura_id', $scoped->id)->update(['asignatura_id' => $oficial->id]);

        $gruposScoped = DB::table('grupos')->where('asignatura_id', $scoped->id)->get();
        foreach ($gruposScoped as $gs) {
            $grupoOficial = DB::table('grupos')
                ->where('asignatura_id', $oficial->id)
                ->where('nombre',     $gs->nombre)
                ->where('gestion',    $gs->gestion)
                ->where('sede_id',    $gs->sede_id)
                ->first();

            if ($grupoOficial) {
                // Colisión en grupo: transferir data
                if (empty($grupoOficial->docente_id) && !empty($gs->docente_id)) {
                    DB::table('grupos')->where('id', $grupoOficial->id)->update(['docente_id' => $gs->docente_id]);
                }
                if (empty($grupoOficial->carrera_id) && !empty($gs->carrera_id)) {
                    DB::table('grupos')->where('id', $grupoOficial->id)->update(['carrera_id' => $gs->carrera_id]);
                }
                DB::table('seguimientos')->where('grupo_id', $gs->id)->update(['grupo_id' => $grupoOficial->id]);
                DB::table('horarios')->where('grupo_id', $gs->id)->update(['grupo_id' => $grupoOficial->id]);
                DB::table('cronogramas')->where('grupo_id', $gs->id)->update(['grupo_id' => $grupoOficial->id]);
                DB::table('grupos')->where('id', $gs->id)->delete();
            } else {
                DB::table('grupos')->where('id', $gs->id)->update(['asignatura_id' => $oficial->id]);
            }
        }
        DB::table('asignaturas')->where('id', $scoped->id)->delete();
        DB::commit();
    } catch (\Exception $e) { 
        DB::rollBack(); 
        echo "  [ERROR GRAVE MERGE] {$scoped->codigo}: {$e->getMessage()}\n"; 
    }
}

// --- PASO 2: RESTORE INTELIGENTE ---
echo "\n[2/3] Restaurando desde Backup (Restore v6)...\n";
$gruposBackup = DB::table("$dbBackup.grupos as g")
    ->join("$dbBackup.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
    ->whereNotNull('g.docente_id')
    ->select(['g.*', 'a.codigo as asig_backup_codigo', 'a.nombre as asig_backup_nombre', 'a.plan_estudios as asig_backup_plan'])
    ->get();

foreach ($gruposBackup as $gb) {
    $codigoBase = $gb->asig_backup_codigo;
    if (preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $gb->asig_backup_codigo, $m)) $codigoBase = $m[1];
    $planRespaldo = $gb->asig_backup_plan ?: 'N';

    $candidatos = DB::table("$dbCurrent.asignaturas")->where('codigo', $codigoBase)->whereNull('deleted_at')->get();
    $asignaturaElegida = null;
    if ($candidatos->count() == 1) $asignaturaElegida = $candidatos->first();
    elseif ($candidatos->count() > 1) {
        $bestScore = -1;
        foreach ($candidatos as $cand) {
            similar_text(strtoupper($gb->asig_backup_nombre), strtoupper($cand->nombre), $percent);
            if (($cand->plan_estudios ?: 'N') == $planRespaldo) $percent += 10;
            if ($percent > $bestScore) { $bestScore = $percent; $asignaturaElegida = $cand; }
        }
    }
    if (!$asignaturaElegida) continue;

    // 2.1 Identificar al Docente Correcto por CI (Robustez v3)
    $docenteActualId = null;
    $gbDocente = DB::table("$dbBackup.docentes")->where('id', $gb->docente_id)->first();
    if ($gbDocente && $gbDocente->ci) {
        $docenteActual = DB::table("$dbCurrent.docentes")->where('ci', $gbDocente->ci)->first();
        if ($docenteActual) {
            $docenteActualId = $docenteActual->id;
        } else {
            // Fallback: Intentar por username del usuario vinculado si existe
            $gbUser = DB::table("$dbBackup.users")->where('id', $gbDocente->user_id)->first();
            if ($gbUser) {
                $uAct = DB::table("$dbCurrent.users")->where('username', $gbUser->username)->first();
                $dAct = DB::table("$dbCurrent.docentes")->where('user_id', $uAct->id)->first();
                $docenteActualId = $dAct?->id;
            }
        }
    }
    
    if (!$docenteActualId) {
        echo "  [SKIP] No se encontró al docente del backup en el sistema actual (CI/ID: {$gbDocente->ci})\n";
        continue;
    }

    // 2.2 Buscar Grupo Actual
    $grupoActual = DB::table("$dbCurrent.grupos")
        ->where('asignatura_id', $asignaturaElegida->id)
        ->where('gestion', $gb->gestion)
        ->where('sede_id', $gb->sede_id)
        ->where('nombre', $gb->nombre)
        ->first();

    $grupoActualId = null;

    if (!$grupoActual) {
        // RECONSTRUCCION DE GRUPO FALTANTE (v3 Self-Healing)
        echo "  [RECUPERACIÓN] Recreando grupo '{$gb->nombre}' para {$asignaturaElegida->codigo} (Docente: $docenteActualId)...\n";
        
        // Asegurar Link Asignatura-Carrera
        $linkExists = DB::table("$dbCurrent.asignatura_carrera")
            ->where('asignatura_id', $asignaturaElegida->id)
            ->where('carrera_id', $gb->carrera_id)
            ->where('sede_id', $gb->sede_id)
            ->exists();

        if (!$linkExists) {
            try {
                DB::table("$dbCurrent.asignatura_carrera")->insert([
                    'asignatura_id' => $asignaturaElegida->id,
                    'carrera_id'    => $gb->carrera_id,
                    'sede_id'       => $gb->sede_id,
                    'semestre'      => 0,
                    'created_at'    => now(),
                    'updated_at'    => now()
                ]);
            } catch (\Exception $e) {}
        }

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
        } catch (\Exception $e) {
            echo "    [ERROR] No se pudo recrear grupo: " . $e->getMessage() . "\n";
            continue;
        }
    } else {
        $grupoActualId = $grupoActual->id;
        // Restaurar docente si está vacío o si es diferente (v3 es más agresivo con la restauración)
        if (empty($grupoActual->docente_id)) {
            DB::table("$dbCurrent.grupos")->where('id', $grupoActualId)->update(['docente_id' => $docenteActualId]);
        }
    }

    // 2.3 Restaurar subordinados
    $cronos = DB::table("$dbBackup.cronogramas")->where('grupo_id', $gb->id)->get();
    foreach ($cronos as $c) {
        if (!DB::table("$dbCurrent.cronogramas")->where('grupo_id', $grupoActualId)->where('fecha', $c->fecha)->where('numero_sesion', $c->numero_sesion)->exists()) {
            try { $d = (array)$c; unset($d['id']); $d['grupo_id'] = $grupoActualId; $d['asignatura_id'] = $asignaturaElegida->id; DB::table("$dbCurrent.cronogramas")->insert($d); } catch (\Exception $e) {}
        }
    }
    $horarios = DB::table("$dbBackup.horarios")->where('grupo_id', $gb->id)->get();
    foreach ($horarios as $h) {
        $ex = !empty($h->id_horario_api) ? DB::table("$dbCurrent.horarios")->where('id_horario_api', $h->id_horario_api)->exists() : DB::table("$dbCurrent.horarios")->where('grupo_id', $grupoActualId)->where('dia', $h->dia)->where('hora_inicio', $h->hora_inicio)->exists();
        if (!$ex) { try { $d = (array)$h; unset($d['id']); $d['grupo_id'] = $grupoActualId; DB::table("$dbCurrent.horarios")->insert($d); } catch (\Exception $e) {} }
    }
    $segs = DB::table("$dbBackup.seguimientos")->where('grupo_id', $gb->id)->get();
    foreach ($segs as $s) {
        if (!DB::table("$dbCurrent.seguimientos")->where('grupo_id', $grupoActualId)->where('fecha', $s->fecha)->where('tema_cumplido', $s->tema_cumplido)->exists()) {
            try { $d = (array)$s; unset($d['id']); $d['grupo_id'] = $grupoActualId; DB::table("$dbCurrent.seguimientos")->insert($d); } catch (\Exception $e) {}
        }
    }
}

// --- PASO 3: LIMPIEZA DE FANTASMAS (Versión Ultra Segura) ---
echo "\n[3/3] Limpiando asignaciones incorrectas (Solo Colisiones)...\n";

$docentesEnActual = DB::table("$dbCurrent.grupos")
    ->whereNotNull('docente_id')
    ->distinct()
    ->pluck('docente_id');

$asignacionesABorrar = 0;

foreach ($docentesEnActual as $docId) {
    // Buscar el equivalente en backup por CI (v3 Robustez)
    $curDocente = DB::table("$dbCurrent.docentes")->where('id', $docId)->first();
    if (!$curDocente || !$curDocente->ci) continue;

    $bakDocente = DB::table("$dbBackup.docentes")->where('ci', $curDocente->ci)->first();
    if (!$bakDocente) continue;

    $bakDocId = $bakDocente->id;

    // 1. Obtener grupos asignados a este docente
    $gsActual = DB::table("$dbCurrent.grupos as g")
        ->join("$dbCurrent.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
        ->where('g.docente_id', $docId)
        ->select('g.id', 'a.codigo', 'a.nombre', 'g.nombre as grupo_nombre')
        ->get();

    // Agrupar por código base para detectar colisiones (ej: ELC-213)
    $grouped = [];
    foreach ($gsActual as $ga) {
        $base = preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $ga->codigo, $m) ? $m[1] : $ga->codigo;
        $grouped[$base][] = $ga;
    }

    foreach ($grouped as $base => $assignments) {
        // Solo actuamos si el docente tiene MÁS de una asignatura con el mismo código base (COLISIÓN)
        // Si solo tiene una, asumimos que es legítima aunque no esté en el backup (Preserva registros nuevos)
        if (count($assignments) <= 1) continue; 

        // Hay colisión. Buscamos cuál de estas es la legítima consultando el backup
        $logicalBackup = DB::table("$dbBackup.grupos as g")
            ->join("$dbBackup.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
            ->where('g.docente_id', $bakDocId)
            ->where(function($q) use ($base) {
                // Buscamos cualquier versión del código base en el backup
                $q->where('a.codigo', 'LIKE', "$base%")
                  ->orWhere('a.codigo', 'REGEXP', "^$base(-|$)");
            })
            ->select('a.nombre', 'g.nombre as grupo_nombre')
            ->get();

        if ($logicalBackup->isEmpty()) {
            // Si el backup no tiene nada para este código, no borramos (podría ser una materia nueva legítima de estos días)
            continue;
        }

        foreach ($assignments as $ga) {
            $nombreGa = strtoupper($ga->nombre);
            // Verificar si esta asignación específica coincide con alguna en el backup
            $match = $logicalBackup->first(function($lb) use ($nombreGa, $ga) {
                if ($lb->grupo_nombre !== $ga->grupo_nombre) return false;
                similar_text(strtoupper($lb->nombre), $nombreGa, $perc);
                return $perc > 80; // Misma materia (ej: PROBABILIDAD vs PROBABILIDADES)
            });

            if (!$match) {
                // Es un FANTASMA: Existe una colisión de código y esta versión no es la que el docente tenía en el backup
                DB::table("$dbCurrent.grupos")->where('id', $ga->id)->update(['docente_id' => null]);
                $asignacionesABorrar++;
            }
        }
    }
}

echo "\nAsignaciones fantasmas (por colisión) eliminadas: $asignacionesABorrar\n";
echo "=== PROCESO COMPLETADO ===\n";
