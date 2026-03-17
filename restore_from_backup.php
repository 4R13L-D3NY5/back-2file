<?php
/**
 * restore_from_backup.php (v6 - Máxima Flexibilidad)
 * ─────────────────────────────────────────────────────────────
 * Recupera docente_id, cronogramas, horarios y seguimientos desde un backup.
 * ─────────────────────────────────────────────────────────────
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// --- CONFIGURACIÓN ---
$dbCurrent = 'academico';        // Nombre de tu DB actual
$dbBackup  = 'academico_backup'; // Nombre de la DB restaurada

echo "=== Restauración desde Backup (v6 - Flexibilidad de Carrera) ===\n\n";

// 1. Verificar existencia de DB Backup
try {
    DB::select("SELECT 1 FROM $dbBackup.asignaturas LIMIT 1");
} catch (\Exception $e) {
    die("ERROR: No se pudo acceder a la base de datos '$dbBackup'. Asegúrate de haberla restaurado con ese nombre.\n");
}

$stats = [
    'docentes_restaurados' => 0,
    'cronogramas_restaurados' => 0,
    'horarios_restaurados' => 0,
    'seguimientos_restaurados' => 0,
    'missing_groups' => 0
];

// 2. Obtener grupos del backup que tenían docente
echo "Consultando grupos con docente en el backup...\n";
$gruposBackup = DB::table("$dbBackup.grupos as g")
    ->join("$dbBackup.asignaturas as a", 'g.asignatura_id', '=', 'a.id')
    ->whereNotNull('g.docente_id')
    ->select([
        'g.*',
        'a.codigo as asig_backup_codigo',
        'a.nombre as asig_backup_nombre',
        'a.plan_estudios as asig_backup_plan'
    ])
    ->get();

echo "Grupos encontrados en backup: " . $gruposBackup->count() . "\n";

foreach ($gruposBackup as $gb) {
    // Normalizar código
    $codigoBase = $gb->asig_backup_codigo;
    if (preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $gb->asig_backup_codigo, $m)) {
        $codigoBase = $m[1];
    }
    $planRespaldo = $gb->asig_backup_plan ?: 'N';

    // 3. Buscar candidatos oficiales
    $candidatos = DB::table("$dbCurrent.asignaturas")
        ->where('codigo', $codigoBase)
        ->whereNull('deleted_at')
        ->where('estado', '!=', 'cancelado')
        ->get();

    $asignaturaElegida = null;

    if ($candidatos->count() == 1) {
        $asignaturaElegida = $candidatos->first();
    } elseif ($candidatos->count() > 1) {
        $bestScore = -1;
        foreach ($candidatos as $cand) {
            similar_text(strtoupper($gb->asig_backup_nombre), strtoupper($cand->nombre), $percent);
            if (($cand->plan_estudios ?: 'N') == $planRespaldo) { $percent += 10; }
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $asignaturaElegida = $cand;
            }
        }
    }

    if (!$asignaturaElegida) {
        // Fallback exacto
        $asignaturaElegida = DB::table("$dbCurrent.asignaturas")
            ->where('codigo', $gb->asig_backup_codigo)
            ->whereNull('deleted_at')
            ->first();
    }

    if (!$asignaturaElegida) {
        $stats['missing_groups']++;
        continue;
    }

    // 4. Buscar el grupo (FLEXIBILIDAD EN CARRERA)
    // Primero intentamos match exacto incluyendo carrera
    $grupoActual = DB::table("$dbCurrent.grupos")
        ->where('asignatura_id', $asignaturaElegida->id)
        ->where('gestion',    $gb->gestion)
        ->where('sede_id',    $gb->sede_id)
        ->where('carrera_id', $gb->carrera_id)
        ->where('nombre',     $gb->nombre)
        ->first();

    if (!$grupoActual) {
        // Segundo intento: Ignorar carrera (usar NULL o cualquier valor) si el resto coincide
        // Esto es necesario porque el sync a veces deja carrera_id en NULL
        $grupoActual = DB::table("$dbCurrent.grupos")
            ->where('asignatura_id', $asignaturaElegida->id)
            ->where('gestion',    $gb->gestion)
            ->where('sede_id',    $gb->sede_id)
            ->where('nombre',     $gb->nombre)
            ->where(function($q) use ($gb) {
                $q->whereNull('carrera_id')
                  ->orWhere('carrera_id', $gb->carrera_id)
                  ->orWhere('carrera_id', '');
            })
            ->first();
    }

    if (!$grupoActual) {
        $stats['missing_groups']++;
        continue;
    }

    // 5. Restaurar Docente
    if (empty($grupoActual->docente_id)) {
        DB::table("$dbCurrent.grupos")->where('id', $grupoActual->id)->update([
            'docente_id' => $gb->docente_id,
            'updated_at' => now()
        ]);
        $stats['docentes_restaurados']++;
    }

    // 6. Restaurar Cronogramas
    $cronosBackup = DB::table("$dbBackup.cronogramas")->where('grupo_id', $gb->id)->get();
    foreach ($cronosBackup as $cb) {
        $existe = DB::table("$dbCurrent.cronogramas")
            ->where('grupo_id', $grupoActual->id)
            ->where('fecha', $cb->fecha)
            ->where('numero_sesion', $cb->numero_sesion)
            ->exists();

        if (!$existe) {
            try {
                $data = (array)$cb;
                unset($data['id']);
                $data['grupo_id'] = $grupoActual->id;
                $data['asignatura_id'] = $asignaturaElegida->id;
                DB::table("$dbCurrent.cronogramas")->insert($data);
                $stats['cronogramas_restaurados']++;
            } catch (\Exception $e) {}
        }
    }

    // 7. Restaurar Horarios
    $horariosBackup = DB::table("$dbBackup.horarios")->where('grupo_id', $gb->id)->get();
    foreach ($horariosBackup as $hb) {
        $existe = false;
        if (!empty($hb->id_horario_api)) {
            $existe = DB::table("$dbCurrent.horarios")->where('id_horario_api', $hb->id_horario_api)->exists();
        } else {
            $existe = DB::table("$dbCurrent.horarios")
                ->where('grupo_id', $grupoActual->id)
                ->where('dia', $hb->dia)
                ->where('hora_inicio', $hb->hora_inicio)
                ->exists();
        }

        if (!$existe) {
            try {
                $data = (array)$hb;
                unset($data['id']);
                $data['grupo_id'] = $grupoActual->id;
                DB::table("$dbCurrent.horarios")->insert($data);
                $stats['horarios_restaurados']++;
            } catch (\Exception $e) {}
        }
    }

    // 8. Restaurar Seguimientos
    $segsBackup = DB::table("$dbBackup.seguimientos")->where('grupo_id', $gb->id)->get();
    foreach ($segsBackup as $sb) {
        $existe = DB::table("$dbCurrent.seguimientos")
            ->where('grupo_id', $grupoActual->id)
            ->where('fecha', $sb->fecha)
            ->where('tema_cumplido', $sb->tema_cumplido)
            ->exists();

        if (!$existe) {
            try {
                $data = (array)$sb;
                unset($data['id']);
                $data['grupo_id'] = $grupoActual->id;
                DB::table("$dbCurrent.seguimientos")->insert($data);
                $stats['seguimientos_restaurados']++;
            } catch (\Exception $e) {}
        }
    }
}

echo "\n=== Resumen de Restauración ===\n";
echo "Docentes re-asignados:    " . $stats['docentes_restaurados'] . "\n";
echo "Cronogramas restaurados:  " . $stats['cronogramas_restaurados'] . "\n";
echo "Horarios restaurados:     " . $stats['horarios_restaurados'] . "\n";
echo "Seguimientos restaurados: " . $stats['seguimientos_restaurados'] . "\n";
echo "Grupos no encontrados:    " . $stats['missing_groups'] . "\n";
echo "\n=== Completado ===\n";
