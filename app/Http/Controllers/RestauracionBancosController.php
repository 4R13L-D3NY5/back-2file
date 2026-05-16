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
                ->where('grupo_teorico', $config->grupo_teorico)->where('parcial', $parcial)->exists();
            if ($yaExiste) continue;
            DB::table('banco_preguntas_configuraciones')->where('id', $config->id)
                ->update(['asignatura_id' => $newAsigId]);
        }
    }
}
