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
        'academicolunes', 'academicomartes', 'academicomiercoles',
        'academicojueves', 'academicoviernes',
    ];

    const CORTE_2DO_PARCIAL = '2026-05-08';

    // ══════════════════════════════════════════════════════════════
    // ENDPOINTS DE LISTADO (UNION para una sola query)
    // ══════════════════════════════════════════════════════════════

    public function sedes(Request $request): JsonResponse
    {
        $sedes = DB::table('sedes')->select('id', 'nombre')->orderBy('nombre')->get();
        return response()->json($sedes);
    }

    public function carreras(Request $request): JsonResponse
    {
        $sedeId = $request->input('sede_id');

        $query = DB::table('carreras')->select('id', 'nombre', 'sigla');

        if ($sedeId) {
            // Carreras vinculadas a esta sede via asignatura_carrera
            $query->whereExists(function ($q) use ($sedeId) {
                $q->select(DB::raw(1))
                  ->from('asignatura_carrera AS ac')
                  ->join('asignaturas AS a', 'a.id', '=', 'ac.asignatura_id')
                  ->whereColumn('ac.carrera_id', 'carreras.id')
                  ->where('ac.sede_id', $sedeId)
                  ->whereNull('a.deleted_at');
            });
        }

        $carreras = $query->orderBy('sigla')->get();
        return response()->json($carreras);
    }

    public function materias(Request $request): JsonResponse
    {
        $sedeId = $request->input('sede_id');
        $carreraId = $request->input('carrera_id');

        $query = DB::table('asignaturas AS a')
            ->whereNull('a.deleted_at');

        if ($sedeId || $carreraId) {
            $query->join('asignatura_carrera AS ac', function ($join) use ($sedeId, $carreraId) {
                $join->on('ac.asignatura_id', '=', 'a.id');
                if ($sedeId) $join->where('ac.sede_id', $sedeId);
                if ($carreraId) $join->where('ac.carrera_id', $carreraId);
            });
        }

        $materias = $query->select('a.codigo', 'a.nombre', 'a.plan_estudios')
            ->distinct()
            ->orderBy('a.codigo')
            ->get()
            ->map(function ($item) {
                $plan = $item->plan_estudios ?? 'N';
                return [
                    'codigo' => $item->codigo,
                    'nombre' => $item->nombre,
                    'plan_estudios' => $plan,
                    'label' => "{$item->codigo} — {$item->nombre} (Plan {$plan})",
                    'value' => $item->codigo . '|' . $plan,
                ];
            })->values();

        return response()->json($materias);
    }

    // ══════════════════════════════════════════════════════════════
    // PREVIEW / EXECUTE
    // ══════════════════════════════════════════════════════════════

    public function preview(Request $request): JsonResponse
    {
        $parcial = $request->input('parcial', '2do Parcial');
        $filters = $this->extractFilters($request);

        $huerfanas = $this->findOrphanedQuestions($parcial, $filters);
        $malAsignadas = $this->findMisassignedQuestions($parcial, $filters);

        $allIds = $huerfanas->pluck('id')
            ->merge($malAsignadas->pluck('id'))
            ->unique()->values();

        $totalEncontradas = $allIds->count();
        $allIds = $allIds->take(500);

        $detalles = collect();
        foreach ($allIds as $preguntaId) {
            $info = $this->analizarPregunta($preguntaId, $parcial);
            if ($info) $detalles->push($info);
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

    public function execute(Request $request): JsonResponse
    {
        $parcial = $request->input('parcial', '2do Parcial');
        $filters = $this->extractFilters($request);

        $huerfanas = $this->findOrphanedQuestions($parcial, $filters);
        $malAsignadas = $this->findMisassignedQuestions($parcial, $filters);

        $allIds = $huerfanas->pluck('id')->merge($malAsignadas->pluck('id'))->unique()->values();

        $restauradas = 0; $yaCorrectas = 0; $sinConsenso = 0; $cambios = [];

        foreach ($allIds as $preguntaId) {
            $resultado = $this->restaurarPregunta($preguntaId, $parcial);
            if ($resultado['status'] === 'restaurada') { $restauradas++; $cambios[] = $resultado; }
            elseif ($resultado['status'] === 'ya_correcta') $yaCorrectas++;
            elseif ($resultado['status'] === 'sin_consenso') $sinConsenso++;
        }

        Log::info('RestauracionBancos: ejecución completada', [
            'total' => $allIds->count(), 'restauradas' => $restauradas,
            'ya_correctas' => $yaCorrectas, 'sin_consenso' => $sinConsenso, 'filters' => $filters,
        ]);

        return response()->json([
            'ok' => true, 'total_procesadas' => $allIds->count(),
            'restauradas' => $restauradas, 'ya_correctas' => $yaCorrectas,
            'sin_consenso' => $sinConsenso, 'cambios' => $cambios,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // QUERIES FILTRADAS
    // ══════════════════════════════════════════════════════════════

    public function previewPlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sede_id' => 'required|integer',
            'carrera_id' => 'required|integer',
            'parcial' => 'nullable|string|max:50',
            'codigo' => 'nullable|string|max:50',
        ]);

        $parcial = $validated['parcial'] ?? '2do Parcial';
        $issues = $this->findPlanIssues(
            (int) $validated['sede_id'],
            (int) $validated['carrera_id'],
            $parcial,
            $validated['codigo'] ?? null
        );

        $detalles = collect($issues);

        return response()->json([
            'ok' => true,
            'sede_id' => (int) $validated['sede_id'],
            'carrera_id' => (int) $validated['carrera_id'],
            'parcial' => $parcial,
            'resumen' => [
                'grupos_revisados' => $this->countPlanGroups(
                    (int) $validated['sede_id'],
                    (int) $validated['carrera_id'],
                    $validated['codigo'] ?? null
                ),
                'materias_detectadas' => $detalles->pluck('codigo')->unique()->count(),
                'grupos_detectados' => $detalles->map(function ($item) {
                    return implode('|', [$item['codigo'], $item['docente_id'], $item['grupo_teorico']]);
                })->unique()->count(),
                'hallazgos' => $detalles->count(),
                'restaurables' => $detalles->where('estado', 'restaurable')->count(),
                'conflictos' => $detalles->where('estado', 'conflicto')->count(),
                'sin_grupo_actual' => $detalles->where('estado', 'sin_grupo_actual')->count(),
                'preguntas_otro_plan' => $detalles->sum('preguntas_otro_plan'),
                'preguntas_plan_correcto' => $detalles->sum('preguntas_plan_correcto'),
            ],
            'detalles' => $detalles->values(),
        ]);
    }

    public function questionsPlan(Request $request): JsonResponse
    {
        $issue = $this->validatePlanIssueRequest($request);
        $limit = max(1, min((int) $request->input('limit', 300), 500));

        $baseQuery = $this->planBankQuery(
            (int) $issue['asignatura_origen_id'],
            (int) $issue['sede_id'],
            (int) $issue['docente_id'],
            $issue['grupo_teorico'],
            $issue['parcial']
        );
        $totalDisponibles = (clone $baseQuery)->count();

        $preguntas = $baseQuery
            ->select('id', 'enunciado', 'tipo', 'dificultad', 'opciones', 'respuesta_correcta', 'imagen')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function ($pregunta) {
                return [
                    'id' => $pregunta->id,
                    'enunciado' => $this->plainQuestionText($pregunta->enunciado),
                    'enunciado_html' => $pregunta->enunciado,
                    'tipo' => $pregunta->tipo,
                    'dificultad' => $pregunta->dificultad,
                    'opciones' => $pregunta->opciones,
                    'respuesta_correcta' => $pregunta->respuesta_correcta,
                    'imagen' => $pregunta->imagen,
                ];
            });

        return response()->json([
            'ok' => true,
            'total' => $totalDisponibles,
            'mostrando' => $preguntas->count(),
            'preguntas' => $preguntas,
        ]);
    }

    public function restorePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.sede_id' => 'required|integer',
            'items.*.carrera_id' => 'required|integer',
            'items.*.docente_id' => 'required|integer',
            'items.*.grupo_teorico' => 'required|string|max:80',
            'items.*.parcial' => 'required|string|max:50',
            'items.*.asignatura_origen_id' => 'required|integer',
            'items.*.asignatura_destino_id' => 'required|integer',
            'items.*.codigo' => 'nullable|string|max:50',
        ]);

        $restaurados = [];
        $omitidos = [];

        DB::transaction(function () use ($validated, &$restaurados, &$omitidos) {
            foreach ($validated['items'] as $item) {
                if (!$this->isValidPlanRestoreItem($item)) {
                    $omitidos[] = [
                        'item' => $this->buildPlanIssueKey($item),
                        'motivo' => 'El origen y destino ya no coinciden con el grupo actual',
                    ];
                    continue;
                }

                $source = $this->planBankQuery(
                    (int) $item['asignatura_origen_id'],
                    (int) $item['sede_id'],
                    (int) $item['docente_id'],
                    $item['grupo_teorico'],
                    $item['parcial']
                );

                $ids = $source->pluck('id');
                if ($ids->isEmpty()) {
                    $omitidos[] = [
                        'item' => $this->buildPlanIssueKey($item),
                        'motivo' => 'Sin preguntas en el plan origen',
                    ];
                    continue;
                }

                $destinoTieneBanco = $this->planBankQuery(
                    (int) $item['asignatura_destino_id'],
                    (int) $item['sede_id'],
                    (int) $item['docente_id'],
                    $item['grupo_teorico'],
                    $item['parcial']
                )->exists();

                if ($destinoTieneBanco) {
                    $omitidos[] = [
                        'item' => $this->buildPlanIssueKey($item),
                        'motivo' => 'El plan correcto ya tiene banco',
                    ];
                    continue;
                }

                DB::table('banco_preguntas')
                    ->whereIn('id', $ids)
                    ->update([
                        'asignatura_id' => (int) $item['asignatura_destino_id'],
                        'updated_at' => now(),
                    ]);

                $this->migrarConfiguracionBancoPlan($item);

                $restaurados[] = [
                    'item' => $this->buildPlanIssueKey($item),
                    'codigo' => $item['codigo'] ?? null,
                    'preguntas_restauradas' => $ids->count(),
                ];
            }
        });

        Log::info('RestauracionBancos: restauracion por plan completada', [
            'user_id' => optional($request->user())->id,
            'restaurados' => count($restaurados),
            'omitidos' => count($omitidos),
        ]);

        return response()->json([
            'ok' => true,
            'restaurados' => $restaurados,
            'omitidos' => $omitidos,
            'total_restaurado' => collect($restaurados)->sum('preguntas_restauradas'),
        ]);
    }

    private function validatePlanIssueRequest(Request $request): array
    {
        return $request->validate([
            'sede_id' => 'required|integer',
            'carrera_id' => 'required|integer',
            'docente_id' => 'required|integer',
            'grupo_teorico' => 'required|string|max:80',
            'parcial' => 'required|string|max:50',
            'asignatura_origen_id' => 'required|integer',
            'asignatura_destino_id' => 'required|integer',
            'codigo' => 'nullable|string|max:50',
        ]);
    }

    private function findPlanIssues(int $sedeId, int $carreraId, string $parcial, ?string $codigo = null): array
    {
        $materiasActualesQuery = DB::table('asignatura_carrera AS ac')
            ->join('asignaturas AS a', 'a.id', '=', 'ac.asignatura_id')
            ->where('ac.sede_id', $sedeId)
            ->where('ac.carrera_id', $carreraId)
            ->whereNull('a.deleted_at')
            ->select('a.id', 'a.codigo', 'a.nombre', 'a.plan_estudios')
            ->distinct();

        if ($codigo) {
            $materiasActualesQuery->where('a.codigo', trim($codigo));
        }

        $materiasActuales = $materiasActualesQuery->get();
        if ($materiasActuales->isEmpty()) {
            return [];
        }

        $materiasPorCodigo = $materiasActuales->groupBy('codigo');
        $codigos = $materiasPorCodigo->keys()->values()->all();
        $grupoTeoricoExpr = DB::raw("TRIM(COALESCE(bp.grupoTeorico, ''))");
        $grupoTeoricoSelect = DB::raw("TRIM(COALESCE(bp.grupoTeorico, '')) AS grupo_teorico");

        $bancosEnOtroPlan = DB::table('banco_preguntas AS bp')
            ->join('asignaturas AS ao', 'ao.id', '=', 'bp.asignatura_id')
            ->leftJoin('docentes AS d', 'd.id', '=', 'bp.docente_id')
            ->where('bp.sede_id', $sedeId)
            ->whereNotNull('bp.docente_id')
            ->whereIn('bp.parcial', $this->parcialValues($parcial))
            ->whereIn('ao.codigo', $codigos)
            ->select([
                'ao.codigo',
                'ao.id AS asignatura_origen_id',
                'ao.nombre AS materia_origen',
                'ao.plan_estudios AS plan_origen',
                'ao.deleted_at AS origen_deleted_at',
                'bp.docente_id',
                $grupoTeoricoSelect,
                'd.nombre_completo AS docente_nombre',
                DB::raw('COUNT(*) AS preguntas_otro_plan'),
            ])
            ->groupBy(
                'ao.codigo',
                'ao.id',
                'ao.nombre',
                'ao.plan_estudios',
                'ao.deleted_at',
                'bp.docente_id',
                $grupoTeoricoExpr,
                'd.nombre_completo'
            )
            ->orderBy('ao.codigo')
            ->get();

        $issues = [];

        foreach ($bancosEnOtroPlan as $banco) {
            $destinos = ($materiasPorCodigo[$banco->codigo] ?? collect())->filter(function ($materia) use ($banco) {
                return (int) $materia->id !== (int) $banco->asignatura_origen_id
                    && $this->normalizePlanValue($materia->plan_estudios)
                        !== $this->normalizePlanValue($banco->plan_origen);
            });

            if ($destinos->isEmpty()) {
                continue;
            }

            $grupoActual = DB::table('grupos AS g')
                ->join('asignaturas AS a', 'a.id', '=', 'g.asignatura_id')
                ->where('g.sede_id', $sedeId)
                ->where('g.carrera_id', $carreraId)
                ->where('g.docente_id', (int) $banco->docente_id)
                ->where('g.nombre', (string) $banco->grupo_teorico)
                ->where('g.estado', 'ACTIVO')
                ->whereNull('g.deleted_at')
                ->where('a.codigo', $banco->codigo)
                ->select(
                    'g.id AS grupo_id',
                    'g.asignatura_id AS asignatura_destino_id',
                    'a.nombre AS asignatura_nombre',
                    'a.plan_estudios AS plan_destino'
                )
                ->first();

            $destino = $grupoActual ?: $destinos->first();
            $destinoId = (int) ($grupoActual->asignatura_destino_id ?? $destino->id);
            $preguntasPlanCorrecto = $this->planBankQuery(
                $destinoId,
                $sedeId,
                (int) $banco->docente_id,
                (string) $banco->grupo_teorico,
                $parcial
            )->count();

            $estado = 'sin_grupo_actual';
            if ($grupoActual && $preguntasPlanCorrecto === 0) {
                $estado = 'restaurable';
            } elseif ($preguntasPlanCorrecto > 0) {
                $estado = 'conflicto';
            }

            $issue = [
                'grupo_id' => $grupoActual->grupo_id ?? null,
                'grupo_actual_encontrado' => $grupoActual !== null,
                'sede_id' => $sedeId,
                'carrera_id' => $carreraId,
                'codigo' => $banco->codigo,
                'materia' => $grupoActual->asignatura_nombre ?? $destino->nombre,
                'grupo_teorico' => (string) $banco->grupo_teorico,
                'docente_id' => $banco->docente_id,
                'docente' => $banco->docente_nombre,
                'parcial' => $parcial,
                'asignatura_destino_id' => $destinoId,
                'plan_destino' => $grupoActual->plan_destino ?? $destino->plan_estudios,
                'asignatura_origen_id' => $banco->asignatura_origen_id,
                'materia_origen' => $banco->materia_origen,
                'plan_origen' => $banco->plan_origen,
                'origen_eliminada' => $banco->origen_deleted_at !== null,
                'preguntas_otro_plan' => (int) $banco->preguntas_otro_plan,
                'preguntas_plan_correcto' => $preguntasPlanCorrecto,
                'estado' => $estado,
            ];
            $issue['issue_key'] = $this->buildPlanIssueKey($issue);
            $issues[] = $issue;
        }

        return $issues;
    }

    private function isValidPlanRestoreItem(array $item): bool
    {
        $destino = DB::table('asignaturas')->where('id', (int) $item['asignatura_destino_id'])->first();
        $origen = DB::table('asignaturas')->where('id', (int) $item['asignatura_origen_id'])->first();

        if (!$destino || !$origen || $destino->codigo !== $origen->codigo) {
            return false;
        }

        if ($this->normalizePlanValue($destino->plan_estudios) === $this->normalizePlanValue($origen->plan_estudios)) {
            return false;
        }

        if (!empty($item['codigo']) && $destino->codigo !== $item['codigo']) {
            return false;
        }

        return DB::table('asignatura_carrera')
            ->where('sede_id', (int) $item['sede_id'])
            ->where('carrera_id', (int) $item['carrera_id'])
            ->where('asignatura_id', (int) $item['asignatura_destino_id'])
            ->exists();
    }

    private function countPlanGroups(int $sedeId, int $carreraId, ?string $codigo = null): int
    {
        $query = DB::table('grupos AS g')
            ->join('asignaturas AS a', 'a.id', '=', 'g.asignatura_id')
            ->where('g.sede_id', $sedeId)
            ->where('g.carrera_id', $carreraId)
            ->whereNull('g.deleted_at')
            ->where('g.estado', 'ACTIVO')
            ->whereNotNull('g.docente_id');

        if ($codigo) {
            $query->where('a.codigo', trim($codigo));
        }

        return $query->count();
    }

    private function planBankQuery(
        int $asignaturaId,
        int $sedeId,
        int $docenteId,
        string $grupoTeorico,
        string $parcial
    ) {
        return DB::table('banco_preguntas')
            ->where('asignatura_id', $asignaturaId)
            ->where('sede_id', $sedeId)
            ->where('docente_id', $docenteId)
            ->whereIn('parcial', $this->parcialValues($parcial))
            ->whereRaw("TRIM(COALESCE(grupoTeorico, '')) = ?", [trim($grupoTeorico)]);
    }

    private function parcialValues(string $parcial): array
    {
        $normalizado = strtoupper(trim($parcial));

        if (in_array($normalizado, ['2P', '2DO PARCIAL', 'SEGUNDO PARCIAL'], true)) {
            return ['2do Parcial', '2P', 'Segundo Parcial', 'SEGUNDO PARCIAL'];
        }

        if (in_array($normalizado, ['1P', '1ER PARCIAL', 'PRIMER PARCIAL'], true)) {
            return ['1er Parcial', '1P', 'Primer Parcial', 'PRIMER PARCIAL'];
        }

        return [$parcial];
    }

    private function normalizePlanValue($plan): string
    {
        $value = strtoupper(trim((string) $plan));
        return $value === '' ? 'SIN_PLAN' : $value;
    }

    private function buildPlanIssueKey(array $item): string
    {
        return implode('|', [
            $item['sede_id'] ?? '',
            $item['carrera_id'] ?? '',
            $item['docente_id'] ?? '',
            $item['grupo_teorico'] ?? '',
            $item['parcial'] ?? '',
            $item['asignatura_origen_id'] ?? '',
            $item['asignatura_destino_id'] ?? '',
        ]);
    }

    private function plainQuestionText(?string $text): string
    {
        $clean = str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $text);
        $clean = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = preg_replace('/\s+/', ' ', $clean);

        return trim(function_exists('mb_substr') ? mb_substr($clean, 0, 260) : substr($clean, 0, 260));
    }

    private function migrarConfiguracionBancoPlan(array $item): void
    {
        $configs = DB::table('banco_preguntas_configuraciones')
            ->where('asignatura_id', (int) $item['asignatura_origen_id'])
            ->where('grupo_teorico', $item['grupo_teorico'])
            ->whereIn('parcial', $this->parcialValues($item['parcial']))
            ->get();

        foreach ($configs as $config) {
            $yaExiste = DB::table('banco_preguntas_configuraciones')
                ->where('asignatura_id', (int) $item['asignatura_destino_id'])
                ->where('sede_id', $config->sede_id)
                ->where('grupo_teorico', $config->grupo_teorico)
                ->where('parcial', $config->parcial)
                ->exists();

            if ($yaExiste) {
                continue;
            }

            DB::table('banco_preguntas_configuraciones')
                ->where('id', $config->id)
                ->update([
                    'asignatura_id' => (int) $item['asignatura_destino_id'],
                    'updated_at' => now(),
                ]);
        }
    }

    private function extractFilters(Request $request): array
    {
        return [
            'sede_id' => $request->input('sede_id'),
            'carrera_id' => $request->input('carrera_id'),
            'codigo' => $request->input('codigo'),
            'plan_estudios' => $request->input('plan_estudios'),
        ];
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['sede_id'])) $query->where('bp.sede_id', $filters['sede_id']);
        if (!empty($filters['codigo'])) {
            $query->where('a.codigo', $filters['codigo']);
            if (!empty($filters['plan_estudios'])) $query->where('a.plan_estudios', $filters['plan_estudios']);
        }
        if (!empty($filters['carrera_id'])) {
            $query->join('asignatura_carrera AS acf', function ($join) {
                $join->on('acf.asignatura_id', '=', 'bp.asignatura_id')
                     ->on('acf.sede_id', '=', 'bp.sede_id');
            })->where('acf.carrera_id', $filters['carrera_id']);
        }
    }

    /**
     * Aplica filtro inteligente de parcial:
     * - Si el usuario busca "2do Parcial", también incluye preguntas de "1er Parcial"
     *   creadas desde mayo 2026 (mal etiquetadas, deben ser 2do).
     * - Si busca "1er Parcial", solo devuelve esas.
     */
    private function applyParcialFilter($query, string $parcial, string $columnaParcial = 'bp.parcial', string $columnaCreatedAt = 'bp.created_at'): void
    {
        if ($parcial === '2do Parcial') {
            $query->where(function ($q) use ($columnaParcial, $columnaCreatedAt) {
                $q->where($columnaParcial, '2do Parcial')
                  ->orWhere(function ($q2) use ($columnaParcial, $columnaCreatedAt) {
                      $q2->where($columnaParcial, '1er Parcial')
                         ->where($columnaCreatedAt, '>=', self::CORTE_2DO_PARCIAL);
                  });
            });
        } else {
            $query->where($columnaParcial, $parcial);
        }
    }

    private function findOrphanedQuestions(string $parcial, array $filters = [])
    {
        $query = DB::table('banco_preguntas AS bp')
            ->join('asignaturas AS a', 'a.id', '=', 'bp.asignatura_id')
            ->whereNotNull('a.deleted_at');
        $this->applyParcialFilter($query, $parcial);
        $this->applyFilters($query, $filters);
        return $query->select('bp.id')->get();
    }

    private function findMisassignedQuestions(string $parcial, array $filters = [])
    {
        $ids = collect();
        foreach (self::BACKUP_DBS as $backupDb) {
            $query = DB::table('banco_preguntas AS bp')
                ->join('asignaturas AS a', 'a.id', '=', 'bp.asignatura_id')
                ->join("{$backupDb}.banco_preguntas AS bl", 'bl.id', '=', 'bp.id')
                ->join("{$backupDb}.asignaturas AS al", 'al.id', '=', 'bl.asignatura_id')
                ->whereNull('a.deleted_at')->whereNull('al.deleted_at')
                ->whereColumn('a.codigo', '!=', 'al.codigo');
            $this->applyParcialFilter($query, $parcial);
            $this->applyFilters($query, $filters);
            foreach ($query->select('bp.id')->get() as $c) {
                if (!$ids->contains($c->id)) $ids->push($c->id);
            }
        }
        return $ids->isNotEmpty()
            ? DB::table('banco_preguntas')->whereIn('id', $ids)->select('id')->get()
            : collect();
    }

    // ══════════════════════════════════════════════════════════════
    // LÓGICA DE RESTAURACIÓN
    // ══════════════════════════════════════════════════════════════

    private function getOldestValidBackup(int $preguntaId): array
    {
        foreach (self::BACKUP_DBS as $backupDb) {
            try {
                $row = DB::table("{$backupDb}.banco_preguntas AS bp")
                    ->join("{$backupDb}.asignaturas AS a", 'a.id', '=', 'bp.asignatura_id')
                    ->where('bp.id', $preguntaId)->whereNull('a.deleted_at')
                    ->select('bp.asignatura_id', 'a.codigo', 'a.plan_estudios')->first();
                if ($row && $row->codigo) {
                    return ['encontrado' => true, 'codigo' => $row->codigo, 'plan' => $row->plan_estudios,
                            'asignatura_id' => $row->asignatura_id, 'backup' => $backupDb];
                }
            } catch (\Throwable $e) {}
        }
        return ['encontrado' => false];
    }

    private function resolverAsignaturaCorrecta(int $preguntaId, $currentAsig, int $sedeId): ?Asignatura
    {
        $backup = $this->getOldestValidBackup($preguntaId);
        if ($backup['encontrado']) { $codigo = $backup['codigo']; $plan = $backup['plan'] ?? 'N'; }
        elseif ($currentAsig && $currentAsig->codigo) { $codigo = $currentAsig->codigo; $plan = $currentAsig->plan_estudios ?: 'N'; }
        else return null;

        $asignatura = Asignatura::where('codigo', $codigo)->where('plan_estudios', $plan)->whereNull('deleted_at')->first();
        $fueRestaurada = false;
        if (!$asignatura) {
            $r = Asignatura::withTrashed()->where('codigo', $codigo)->where('plan_estudios', $plan)->whereNotNull('deleted_at')->first();
            if ($r) { $r->deleted_at = null; $r->save(); $fueRestaurada = true; $asignatura = $r;
                Log::info("RestauracionBancos: asignatura restaurada", ['id'=>$r->id,'codigo'=>$r->codigo,'plan'=>$r->plan_estudios,'backup'=>$backup['backup']??'ninguno']); }
        }
        if (!$asignatura) $asignatura = Asignatura::where('codigo', $codigo)->whereNull('deleted_at')->first();
        if (!$asignatura) return null;
        $this->asegurarPivoteSede($asignatura, $sedeId, $codigo, $plan, $fueRestaurada);
        return $asignatura;
    }

    private function asegurarPivoteSede(Asignatura $asignatura, int $sedeId, string $codigo, ?string $plan, bool $fueRestaurada): void
    {
        if (DB::table('asignatura_carrera')->where('asignatura_id', $asignatura->id)->where('sede_id', $sedeId)->exists()) return;
        $ph = DB::table('asignatura_carrera AS ac')->join('asignaturas AS a', 'a.id', '=', 'ac.asignatura_id')
            ->where('a.codigo', $codigo)->where('a.id', '!=', $asignatura->id)->whereNull('a.deleted_at')
            ->where('ac.sede_id', $sedeId)->select('ac.carrera_id', 'ac.semestre')->first();
        if ($ph) {
            DB::table('asignatura_carrera')->insert(['asignatura_id'=>$asignatura->id,'carrera_id'=>$ph->carrera_id,'sede_id'=>$sedeId,'semestre'=>$ph->semestre,'created_at'=>now(),'updated_at'=>now()]);
        } else {
            Log::warning("RestauracionBancos: sin pivote sede {$sedeId}", ['asignatura_id'=>$asignatura->id,'codigo'=>$codigo,'plan'=>$plan]);
        }
    }

    /**
     * Determina si una pregunta debe corregirse a 2do Parcial automáticamente.
     * Si created_at >= inicio del 2do parcial (08/may/2026) y dice "1er Parcial" → auto-corregir.
     */
    private function debeSer2doParcial($pregunta): bool
    {
        return $pregunta->created_at
            && $pregunta->created_at >= self::CORTE_2DO_PARCIAL
            && $pregunta->parcial === '1er Parcial';
    }

    private function analizarPregunta(int $preguntaId, string $parcial): ?array
    {
        $pregunta = DB::table('banco_preguntas')
            ->where('id', $preguntaId)
            ->select('id', 'asignatura_id', 'parcial', 'docente_id', 'sede_id', 'grupoTeorico', 'created_at')
            ->first();
        if (!$pregunta) return null;

        $currentAsig = DB::table('asignaturas')->where('id', $pregunta->asignatura_id)
            ->select('id', 'codigo', 'plan_estudios', 'nombre', 'deleted_at')->first();

        $backup = $this->getOldestValidBackup($preguntaId);
        $asignaturaCorrecta = $this->resolverAsignaturaCorrecta($preguntaId, $currentAsig, $pregunta->sede_id);
        $auto2do = $this->debeSer2doParcial($pregunta);

        $base = [
            'pregunta_id' => $preguntaId,
            'parcial' => $pregunta->parcial,
            'parcial_final' => $auto2do ? '2do Parcial' : $pregunta->parcial,
            'auto_2do_parcial' => $auto2do,
            'docente_id' => $pregunta->docente_id,
            'sede_id' => $pregunta->sede_id,
            'grupo_teorico' => $pregunta->grupoTeorico,
            'backup_fuente' => $backup['backup'] ?? 'ninguno',
            'created_at' => $pregunta->created_at,
        ];

        if (!$asignaturaCorrecta) {
            return array_merge($base, [
                'asignatura_actual' => $currentAsig ? "{$currentAsig->codigo} ({$currentAsig->id})" : 'N/A',
                'asignatura_sugerida' => $backup['codigo'] ?? ($currentAsig->codigo ?? '?'),
                'accion' => 'sin_consenso',
            ]);
        }

        if ($pregunta->asignatura_id == $asignaturaCorrecta->id && !$auto2do) {
            return array_merge($base, [
                'asignatura_actual' => "{$asignaturaCorrecta->codigo} ({$asignaturaCorrecta->id})",
                'asignatura_sugerida' => "{$asignaturaCorrecta->codigo} ({$asignaturaCorrecta->id})",
                'accion' => 'ya_correcta',
            ]);
        }

        return array_merge($base, [
            'asignatura_actual' => $currentAsig ? "{$currentAsig->codigo} ({$currentAsig->id})" : 'N/A',
            'asignatura_sugerida' => "{$asignaturaCorrecta->codigo} ({$asignaturaCorrecta->id})",
            'accion' => 'restaurar',
        ]);
    }

    private function restaurarPregunta(int $preguntaId, string $parcial): array
    {
        $pregunta = DB::table('banco_preguntas')
            ->where('id', $preguntaId)
            ->select('id', 'asignatura_id', 'parcial', 'docente_id', 'sede_id', 'grupoTeorico', 'created_at')
            ->first();
        if (!$pregunta) return ['status' => 'error', 'message' => 'Pregunta no encontrada'];

        $currentAsig = DB::table('asignaturas')->where('id', $pregunta->asignatura_id)
            ->select('id', 'codigo', 'plan_estudios', 'deleted_at')->first();

        $asignaturaCorrecta = $this->resolverAsignaturaCorrecta($preguntaId, $currentAsig, $pregunta->sede_id);
        if (!$asignaturaCorrecta) return ['status' => 'sin_consenso', 'pregunta_id' => $preguntaId];

        $auto2do = $this->debeSer2doParcial($pregunta);
        $necesitaCambioAsig = $pregunta->asignatura_id != $asignaturaCorrecta->id;

        if (!$necesitaCambioAsig && !$auto2do) {
            return ['status' => 'ya_correcta', 'pregunta_id' => $preguntaId];
        }

        $updateData = [];
        if ($necesitaCambioAsig) $updateData['asignatura_id'] = $asignaturaCorrecta->id;
        if ($auto2do) $updateData['parcial'] = '2do Parcial';

        DB::table('banco_preguntas')->where('id', $preguntaId)->update($updateData);

        if ($necesitaCambioAsig) {
            $this->migrarConfiguracionesSeguro($pregunta->asignatura_id, $asignaturaCorrecta->id, $parcial);
        }

        $acciones = [];
        if ($necesitaCambioAsig) $acciones[] = 'asignatura';
        if ($auto2do) $acciones[] = 'parcial';

        return [
            'status' => 'restaurada',
            'pregunta_id' => $preguntaId,
            'old_asignatura_id' => $necesitaCambioAsig ? $pregunta->asignatura_id : null,
            'new_asignatura_id' => $necesitaCambioAsig ? $asignaturaCorrecta->id : null,
            'codigo' => $asignaturaCorrecta->codigo,
            'plan' => $asignaturaCorrecta->plan_estudios,
            'acciones' => implode(' + ', $acciones),
        ];
    }

    private function migrarConfiguracionesSeguro(int $oldAsigId, int $newAsigId, string $parcial): void
    {
        $configs = DB::table('banco_preguntas_configuraciones')
            ->where('asignatura_id', $oldAsigId)->where('parcial', $parcial)->get();
        foreach ($configs as $config) {
            $yaExiste = DB::table('banco_preguntas_configuraciones')
                ->where('asignatura_id', $newAsigId)
                ->where('sede_id', $config->sede_id)
                ->where('grupo_teorico', $config->grupo_teorico)->where('parcial', $parcial)->exists();
            if ($yaExiste) continue;
            DB::table('banco_preguntas_configuraciones')->where('id', $config->id)
                ->update(['asignatura_id' => $newAsigId]);
        }
    }
}
