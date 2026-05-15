<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestauracionBancosController extends Controller
{
    const BACKUP_DBS = [
        'academicolunes',
        'academicomartes',
        'academicomiercoles',
        'academicojueves',
        'academicoviernes',
    ];

    /**
     * Previsualizar preguntas afectadas sin modificar.
     * POST /api/restauracion/bancos/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $parcial = $request->input('parcial', '2do Parcial');

        $huerfanas = $this->findOrphanedQuestions($parcial);
        $malAsignadas = $this->findMisassignedQuestions($parcial);

        $allIds = $huerfanas->pluck('id')
            ->merge($malAsignadas->pluck('id'))
            ->unique()
            ->values();

        $totalEncontradas = $allIds->count();
        $allIds = $allIds->take(500);

        $detalles = collect();
        foreach ($allIds as $preguntaId) {
            $info = $this->analizarPregunta($preguntaId, $parcial);
            if ($info) {
                $detalles->push($info);
            }
        }

        return response()->json([
            'total_huerfanas' => $huerfanas->count(),
            'total_mal_asignadas' => $malAsignadas->count(),
            'total_encontradas' => $totalEncontradas,
            'mostrando' => $detalles->count(),
            'total_procesar' => $detalles->count(),
            'restaurables' => $detalles->where('accion', 'restaurar')->count(),
            'ya_correctas' => $detalles->where('accion', 'ya_correcta')->count(),
            'sin_consenso' => $detalles->where('accion', 'sin_consenso')->count(),
            'detalles' => $detalles->values(),
        ]);
    }

    /**
     * Ejecutar restauración.
     * POST /api/restauracion/bancos/execute
     */
    public function execute(Request $request): JsonResponse
    {
        $parcial = $request->input('parcial', '2do Parcial');

        $huerfanas = $this->findOrphanedQuestions($parcial);
        $malAsignadas = $this->findMisassignedQuestions($parcial);

        $allIds = $huerfanas->pluck('id')
            ->merge($malAsignadas->pluck('id'))
            ->unique()
            ->values();

        $restauradas = 0;
        $yaCorrectas = 0;
        $sinConsenso = 0;
        $cambios = [];

        foreach ($allIds as $preguntaId) {
            $resultado = $this->restaurarPregunta($preguntaId, $parcial);

            if ($resultado['status'] === 'restaurada') {
                $restauradas++;
                $cambios[] = $resultado;
            } elseif ($resultado['status'] === 'ya_correcta') {
                $yaCorrectas++;
            } elseif ($resultado['status'] === 'sin_consenso') {
                $sinConsenso++;
            }
        }

        Log::info('RestauracionBancosController: ejecución completada', [
            'total' => $allIds->count(),
            'restauradas' => $restauradas,
            'ya_correctas' => $yaCorrectas,
            'sin_consenso' => $sinConsenso,
        ]);

        return response()->json([
            'ok' => true,
            'total_procesadas' => $allIds->count(),
            'restauradas' => $restauradas,
            'ya_correctas' => $yaCorrectas,
            'sin_consenso' => $sinConsenso,
            'cambios' => $cambios,
        ]);
    }

    private function findOrphanedQuestions(string $parcial)
    {
        return DB::table('banco_preguntas AS bp')
            ->join('asignaturas AS a', 'a.id', '=', 'bp.asignatura_id')
            ->whereNotNull('a.deleted_at')
            ->where('bp.parcial', $parcial)
            ->select('bp.id')
            ->get();
    }

    /**
     * Buscar preguntas mal asignadas comparando código actual contra
     * CADA backup individual. Si cualquier backup muestra un código diferente,
     * la pregunta se marca como candidata.
     */
    private function findMisassignedQuestions(string $parcial)
    {
        $ids = collect();

        foreach (self::BACKUP_DBS as $backupDb) {
            $candidatas = $this->findCodigoDifferencesWithBackup($parcial, $backupDb);
            foreach ($candidatas as $c) {
                if (!$ids->contains($c->id)) {
                    $ids->push($c->id);
                }
            }
        }

        return $ids->isNotEmpty()
            ? DB::table('banco_preguntas')->whereIn('id', $ids)->select('id')->get()
            : collect();
    }

    private function findCodigoDifferencesWithBackup(string $parcial, string $backupDb)
    {
        return DB::table('banco_preguntas AS bp')
            ->join('asignaturas AS a', 'a.id', '=', 'bp.asignatura_id')
            ->join("{$backupDb}.banco_preguntas AS bl", 'bl.id', '=', 'bp.id')
            ->join("{$backupDb}.asignaturas AS al", 'al.id', '=', 'bl.asignatura_id')
            ->where('bp.parcial', $parcial)
            ->whereNull('a.deleted_at')
            ->whereNull('al.deleted_at')
            ->whereColumn('a.codigo', '!=', 'al.codigo')
            ->select('bp.id', 'a.codigo as codigo_actual', 'al.codigo as codigo_backup')
            ->get();
    }

    /**
     * Buscar en los backups del MÁS ANTIGUO al MÁS RECIENTE.
     * Retorna el primer backup donde la pregunta existe con asignatura activa.
     * Esto evita que backups corruptos (mayoría) ganen sobre los correctos (minoría).
     */
    private function getOldestValidBackup(int $preguntaId): array
    {
        foreach (self::BACKUP_DBS as $backupDb) {
            try {
                $row = DB::table("{$backupDb}.banco_preguntas AS bp")
                    ->join("{$backupDb}.asignaturas AS a", 'a.id', '=', 'bp.asignatura_id')
                    ->where('bp.id', $preguntaId)
                    ->whereNull('a.deleted_at')
                    ->select('bp.asignatura_id', 'a.codigo', 'a.plan_estudios')
                    ->first();

                if ($row && $row->codigo) {
                    return [
                        'encontrado' => true,
                        'codigo' => $row->codigo,
                        'plan' => $row->plan_estudios,
                        'asignatura_id' => $row->asignatura_id,
                        'backup' => $backupDb,
                    ];
                }
            } catch (\Throwable $e) {
                // backup no disponible
            }
        }

        return ['encontrado' => false];
    }

    /**
     * Determinar la asignatura correcta usando el backup más antiguo válido.
     * Si no hay backup, usar los datos de la asignatura soft-deleteada actual.
     */
    private function resolverAsignaturaCorrecta(int $preguntaId, $currentAsig, int $sedeId): ?Asignatura
    {
        $backup = $this->getOldestValidBackup($preguntaId);

        $codigo = null;
        $plan = null;

        if ($backup['encontrado']) {
            $codigo = $backup['codigo'];
            $plan = $backup['plan'] ?? 'N';
        } elseif ($currentAsig && $currentAsig->codigo) {
            $codigo = $currentAsig->codigo;
            $plan = $currentAsig->plan_estudios ?: 'N';
        } else {
            return null;
        }

        // Buscar activa con codigo + plan exacto
        $asignatura = Asignatura::where('codigo', $codigo)
            ->where('plan_estudios', $plan)
            ->whereNull('deleted_at')
            ->first();

        // Si NO existe activa, restaurar la soft-deleteada
        $fueRestaurada = false;
        if (!$asignatura) {
            $restaurada = Asignatura::withTrashed()
                ->where('codigo', $codigo)
                ->where('plan_estudios', $plan)
                ->whereNotNull('deleted_at')
                ->first();

            if ($restaurada) {
                $restaurada->deleted_at = null;
                $restaurada->save();
                $fueRestaurada = true;
                Log::info("RestauracionBancos: asignatura restaurada", [
                    'id' => $restaurada->id,
                    'codigo' => $restaurada->codigo,
                    'plan' => $restaurada->plan_estudios,
                    'backup' => $backup['backup'] ?? 'ninguno',
                ]);
                $asignatura = $restaurada;
            }
        }

        // Fallback: cualquier plan activo con ese código
        if (!$asignatura) {
            $asignatura = Asignatura::where('codigo', $codigo)
                ->whereNull('deleted_at')
                ->first();
        }

        if (!$asignatura) return null;

        // Reparar pivote sede si es necesario
        $this->asegurarPivoteSede($asignatura, $sedeId, $codigo, $plan, $fueRestaurada);

        return $asignatura;
    }

    /**
     * Validación cruzada: verificar que la asignatura tenga un grupo
     * que coincida con docente + sede + grupoTeorico de la pregunta.
     */
    private function validarConGrupo(Asignatura $asignatura, $pregunta): bool
    {
        $grupoNombre = $pregunta->grupoTeorico;
        if (!$grupoNombre) return true; // sin grupoTeorico, no se puede validar

        $grupo = DB::table('grupos')
            ->where('asignatura_id', $asignatura->id)
            ->where('sede_id', $pregunta->sede_id)
            ->where('docente_id', $pregunta->docente_id)
            ->where('nombre', $grupoNombre)
            ->first();

        return $grupo !== null;
    }

    private function asegurarPivoteSede(Asignatura $asignatura, int $sedeId, string $codigo, ?string $plan, bool $fueRestaurada): void
    {
        $tieneSede = DB::table('asignatura_carrera')
            ->where('asignatura_id', $asignatura->id)
            ->where('sede_id', $sedeId)
            ->exists();

        if ($tieneSede) return;

        $pivoteHermano = DB::table('asignatura_carrera AS ac')
            ->join('asignaturas AS a', 'a.id', '=', 'ac.asignatura_id')
            ->where('a.codigo', $codigo)
            ->where('a.id', '!=', $asignatura->id)
            ->whereNull('a.deleted_at')
            ->where('ac.sede_id', $sedeId)
            ->select('ac.carrera_id', 'ac.semestre')
            ->first();

        if ($pivoteHermano) {
            DB::table('asignatura_carrera')->insert([
                'asignatura_id' => $asignatura->id,
                'carrera_id' => $pivoteHermano->carrera_id,
                'sede_id' => $sedeId,
                'semestre' => $pivoteHermano->semestre,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Log::info("RestauracionBancos: pivote creado", [
                'asignatura_id' => $asignatura->id,
                'codigo' => $codigo,
                'sede_id' => $sedeId,
                'carrera_id' => $pivoteHermano->carrera_id,
            ]);
        } else {
            Log::warning("RestauracionBancos: no se pudo crear pivote para sede {$sedeId}", [
                'asignatura_id' => $asignatura->id,
                'codigo' => $codigo,
                'plan' => $plan,
            ]);
        }
    }

    /**
     * Analizar una pregunta (para preview) sin modificar.
     */
    private function analizarPregunta(int $preguntaId, string $parcial): ?array
    {
        $pregunta = DB::table('banco_preguntas')
            ->where('id', $preguntaId)
            ->select('id', 'asignatura_id', 'parcial', 'docente_id', 'sede_id', 'grupoTeorico')
            ->first();

        if (!$pregunta) return null;

        $currentAsig = DB::table('asignaturas')
            ->where('id', $pregunta->asignatura_id)
            ->select('id', 'codigo', 'plan_estudios', 'nombre', 'deleted_at')
            ->first();

        $backup = $this->getOldestValidBackup($preguntaId);
        $asignaturaCorrecta = $this->resolverAsignaturaCorrecta($preguntaId, $currentAsig, $pregunta->sede_id);

        $base = [
            'pregunta_id' => $preguntaId,
            'parcial' => $pregunta->parcial,
            'docente_id' => $pregunta->docente_id,
            'sede_id' => $pregunta->sede_id,
            'grupo_teorico' => $pregunta->grupoTeorico,
            'backup_fuente' => $backup['backup'] ?? 'ninguno',
        ];

        if (!$asignaturaCorrecta) {
            return array_merge($base, [
                'asignatura_actual' => $currentAsig ? "{$currentAsig->codigo} (id:{$currentAsig->id})" : 'N/A',
                'asignatura_sugerida' => $backup['codigo'] ?? ($currentAsig->codigo ?? '?'),
                'accion' => 'sin_consenso',
            ]);
        }

        if ($pregunta->asignatura_id == $asignaturaCorrecta->id) {
            return array_merge($base, [
                'asignatura_actual' => "{$asignaturaCorrecta->codigo} (id:{$asignaturaCorrecta->id})",
                'asignatura_sugerida' => "{$asignaturaCorrecta->codigo} (id:{$asignaturaCorrecta->id})",
                'accion' => 'ya_correcta',
            ]);
        }

        return array_merge($base, [
            'asignatura_actual' => $currentAsig ? "{$currentAsig->codigo} (id:{$currentAsig->id})" : 'N/A',
            'asignatura_sugerida' => "{$asignaturaCorrecta->codigo} (id:{$asignaturaCorrecta->id})",
            'accion' => 'restaurar',
        ]);
    }

    /**
     * Restaurar una pregunta individual.
     */
    private function restaurarPregunta(int $preguntaId, string $parcial): array
    {
        $pregunta = DB::table('banco_preguntas')
            ->where('id', $preguntaId)
            ->select('id', 'asignatura_id', 'parcial', 'docente_id', 'sede_id', 'grupoTeorico')
            ->first();

        if (!$pregunta) {
            return ['status' => 'error', 'message' => 'Pregunta no encontrada'];
        }

        $currentAsig = DB::table('asignaturas')
            ->where('id', $pregunta->asignatura_id)
            ->select('id', 'codigo', 'plan_estudios', 'deleted_at')
            ->first();

        $asignaturaCorrecta = $this->resolverAsignaturaCorrecta($preguntaId, $currentAsig, $pregunta->sede_id);

        if (!$asignaturaCorrecta) {
            return ['status' => 'sin_consenso', 'pregunta_id' => $preguntaId];
        }

        if ($pregunta->asignatura_id == $asignaturaCorrecta->id) {
            return ['status' => 'ya_correcta', 'pregunta_id' => $preguntaId];
        }

        DB::table('banco_preguntas')
            ->where('id', $preguntaId)
            ->update(['asignatura_id' => $asignaturaCorrecta->id]);

        $this->migrarConfiguracionesSeguro($pregunta->asignatura_id, $asignaturaCorrecta->id, $parcial);

        return [
            'status' => 'restaurada',
            'pregunta_id' => $preguntaId,
            'old_asignatura_id' => $pregunta->asignatura_id,
            'new_asignatura_id' => $asignaturaCorrecta->id,
            'codigo' => $asignaturaCorrecta->codigo,
            'plan' => $asignaturaCorrecta->plan_estudios,
        ];
    }

    /**
     * Migrar configuraciones de banco_preguntas_configuraciones de forma segura,
     * verificando el unique constraint (asignatura_id, grupo_teorico, parcial)
     * antes de cada UPDATE para evitar errores 1062 Duplicate entry.
     */
    private function migrarConfiguracionesSeguro(int $oldAsigId, int $newAsigId, string $parcial): void
    {
        $configs = DB::table('banco_preguntas_configuraciones')
            ->where('asignatura_id', $oldAsigId)
            ->where('parcial', $parcial)
            ->get();

        foreach ($configs as $config) {
            $yaExiste = DB::table('banco_preguntas_configuraciones')
                ->where('asignatura_id', $newAsigId)
                ->where('grupo_teorico', $config->grupo_teorico)
                ->where('parcial', $parcial)
                ->exists();

            if ($yaExiste) {
                Log::info("RestauracionBancos: config duplicada, omitiendo", [
                    'id' => $config->id,
                    'grupo_teorico' => $config->grupo_teorico,
                    'old_asig' => $oldAsigId,
                    'new_asig' => $newAsigId,
                ]);
                continue;
            }

            DB::table('banco_preguntas_configuraciones')
                ->where('id', $config->id)
                ->update(['asignatura_id' => $newAsigId]);
        }
    }
}
