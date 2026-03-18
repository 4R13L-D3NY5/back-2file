<?php

namespace App\Http\Controllers;

use App\Models\PlanningCache;
use App\Models\PlanningSyncLog;
use App\Services\GruposExternoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanningCacheController extends Controller
{
    protected GruposExternoService $service;

    /**
     * Lista fija de siglas de carreras Cochabamba (sede_api_id=1).
     * Fuente de verdad confirmada con la API Planning.
     */
    public const SIGLAS_COCHABAMBA = [
        'caradm', // LICENCIATURA EN ADMINISTRACIÓN DE EMPRESAS
        'caraye', // LICENCIATURA EN ARTE Y ESCULTURA
        'carbyf', // LICENCIATURA EN BIOQUÍMICA Y FARMACIA
        'carcad', // COMPLEMENTARIA EN ADMINISTRACIÓN DE EMPRESAS
        'carccp', // COMPLEMENTARIA CONTADURÍA PÚBLICA
        'carcic', // COMPLEMENTARIA INGENIERÍA COMERCIAL
        'carcne', // LICENCIATURA EN CINEMATOGRAFÍA
        'carcpu', // LICENCIATURA EN CONTADURÍA PÚBLICA
        'carcso', // LICENCIATURA EN COMUNICACIÓN SOCIAL
        'carder', // LICENCIATURA EN DERECHO
        'careco', // LICENCIATURA EN ECONOMÍA
        'carele', // LICENCIATURA EN INGENIERÍA ELECTRONICA
        'carenl', // LICENCIATURA EN ENFERMERÍA
        'carfis', // LICENCIATURA EN FISIOTERAPIA Y KINESIOLOGÍA
        'carfon', // LICENCIATURA EN FONOAUDIOLOGIA
        'caribi', // LICENCIATURA EN INGENIERÍA BIOMÉDICA
        'carico', // LICENCIATURA EN INGENIERÍA COMERCIAL
        'carmed', // LICENCIATURA EN MEDICINA
        'carnyd', // LICENCIATURA EN NUTRICIÓN Y DIETÉTICA
        'carodo', // LICENCIATURA EN ODONTOLOGÍA
        'carpro', // PROTESIS DENTAL
        'carsis', // LICENCIATURA EN INGENIERÍA DE SISTEMAS
        'carson', // LICENCIATURA EN INGENIERÍA DE SONIDO
        'carvet', // LICENCIATURA EN MEDICINA VETERINARIA Y ZOOTECNIA
    ];

    public function __construct(GruposExternoService $service)
    {
        $this->service = $service;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/planning/sincronizar-cochabamba
    // Sincroniza TODAS las carreras de Cochabamba en una sola operación.
    // Body: { "gestion": "1-2026" }
    // ─────────────────────────────────────────────────────────────────────────
    public function sincronizarCochabamba(Request $request): JsonResponse
    {
        $request->validate([
            'gestion' => 'required|string|max:20',
        ]);

        $gestion   = trim($request->input('gestion'));
        $sedeApiId = 1;
        $userId    = Auth::id();

        $syncLog = PlanningSyncLog::create([
            'gestion'        => $gestion,
            'sede_api_id'    => $sedeApiId,
            'carrera_codigo' => null, // null = todas
            'user_id'        => $userId,
            'status'         => 'running',
            'started_at'     => now(),
        ]);

        $totalRegistros = 0;
        $totalCarreras  = 0;
        $resultados     = []; // detalle por carrera
        $now            = now();

        DB::beginTransaction();
        try {
            // Borrar cache anterior completo de esta gestión+sede
            PlanningCache::where('gestion', $gestion)
                ->where('sede_api_id', $sedeApiId)
                ->where('plan_estudios', 'N')
                ->delete();

            foreach (self::SIGLAS_COCHABAMBA as $codigoApi) {
                $resultado = $this->sincronizarUnaCarrera($gestion, $codigoApi, $sedeApiId, $now);
                $resultados[] = $resultado;

                if ($resultado['status'] === 'ok') {
                    $totalRegistros += $resultado['registros'];
                    $totalCarreras++;
                }
            }

            DB::commit();

            $syncLog->update([
                'status'          => 'completed',
                'total_registros' => $totalRegistros,
                'total_carreras'  => $totalCarreras,
                'finished_at'     => now(),
                'error_message'   => null,
            ]);

            return response()->json([
                'success'         => true,
                'message'         => "Sincronización completada: {$totalCarreras} carreras con datos, {$totalRegistros} registros.",
                'total_carreras'  => $totalCarreras,
                'total_registros' => $totalRegistros,
                'gestion'         => $gestion,
                'resultados'      => $resultados,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PlanningCache: Error general: ' . $e->getMessage());

            $syncLog->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at'   => now(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al sincronizar: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/planning/sincronizar-carrera
    // Sincroniza UNA sola carrera sin afectar las demás.
    // Body: { "gestion": "1-2026", "carrera_codigo": "CARENL" }
    // ─────────────────────────────────────────────────────────────────────────
    public function sincronizarCarrera(Request $request): JsonResponse
    {
        $request->validate([
            'gestion'       => 'required|string|max:20',
            'carrera_codigo' => 'required|string|max:20',
        ]);

        $gestion      = trim($request->input('gestion'));
        $codigoApi    = strtolower(trim($request->input('carrera_codigo')));
        $sedeApiId    = 1;

        if (!in_array($codigoApi, self::SIGLAS_COCHABAMBA)) {
            return response()->json([
                'success' => false,
                'message' => "La carrera '{$codigoApi}' no está en la lista de Cochabamba.",
            ], 422);
        }

        $now = now();

        DB::beginTransaction();
        try {
            // Borrar solo los registros de ESTA carrera en esta gestión
            PlanningCache::where('gestion', $gestion)
                ->where('sede_api_id', $sedeApiId)
                ->where('carrera_codigo', strtoupper($codigoApi))
                ->where('plan_estudios', 'N')
                ->delete();

            // Limpiar cache de Laravel para forzar nueva llamada a la API
            $this->service->limpiarCache($gestion, $codigoApi, $sedeApiId);

            $resultado = $this->sincronizarUnaCarrera($gestion, $codigoApi, $sedeApiId, $now);

            DB::commit();

            return response()->json([
                'success'   => $resultado['status'] === 'ok',
                'message'   => $resultado['status'] === 'ok'
                    ? "Carrera {$codigoApi} sincronizada: {$resultado['registros']} registros."
                    : "Error en {$codigoApi}: {$resultado['error']}",
                'resultado' => $resultado,
                'gestion'   => $gestion,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("PlanningCache: Error sincronizando {$codigoApi}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al sincronizar: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/planning/sync-status
    // Estado general + detalle por carrera para una gestión dada.
    // ─────────────────────────────────────────────────────────────────────────
    public function syncStatus(Request $request): JsonResponse
    {
        $gestion   = $request->input('gestion');
        $sedeApiId = 1;

        $ultimoLog = PlanningSyncLog::where('sede_api_id', $sedeApiId)
            ->whereNull('carrera_codigo')
            ->when($gestion, fn($q) => $q->where('gestion', $gestion))
            ->orderByDesc('id')
            ->first();

        // Contar registros por carrera en BD
        $query = PlanningCache::where('sede_api_id', $sedeApiId)
            ->where('plan_estudios', 'N')
            ->when($gestion, fn($q) => $q->where('gestion', $gestion))
            ->selectRaw('carrera_codigo, COUNT(*) as total_registros, MAX(sincronizado_at) as ultimo_sync')
            ->groupBy('carrera_codigo')
            ->get()
            ->keyBy('carrera_codigo');

        // Construir detalle por carrera
        $detalleCarreras = [];
        foreach (self::SIGLAS_COCHABAMBA as $sigla) {
            $upper = strtoupper($sigla);
            $enBD  = $query->get($upper);
            $detalleCarreras[] = [
                'codigo'          => $upper,
                'registros_en_bd' => $enBD ? (int) $enBD->total_registros : 0,
                'sincronizado'    => $enBD && $enBD->total_registros > 0,
                'sincronizado_at' => $enBD ? $enBD->ultimo_sync : null,
            ];
        }

        $totalConDatos = count(array_filter($detalleCarreras, fn($c) => $c['sincronizado']));

        return response()->json([
            'success'          => true,
            'gestion'          => $gestion,
            'sincronizado'     => $ultimoLog?->status === 'completed',
            'ultimo_sync'      => $ultimoLog ? [
                'status'          => $ultimoLog->status,
                'total_registros' => $ultimoLog->total_registros,
                'total_carreras'  => $ultimoLog->total_carreras,
                'started_at'      => $ultimoLog->started_at,
                'finished_at'     => $ultimoLog->finished_at,
            ] : null,
            'total_carreras_con_datos' => $totalConDatos,
            'total_carreras_lista'     => count(self::SIGLAS_COCHABAMBA),
            'carreras'         => $detalleCarreras,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/planning/cache
    // Lee datos guardados para una carrera+gestión.
    // ─────────────────────────────────────────────────────────────────────────
    public function getCache(Request $request): JsonResponse
    {
        $gestion       = $request->input('gestion');
        $carreraCodigo = strtoupper(trim($request->input('carrera_codigo', '')));
        $sedeApiId     = (int) $request->input('sede_api_id', 1);

        if (!$gestion || !$carreraCodigo) {
            return response()->json([
                'success' => false,
                'message' => 'gestion y carrera_codigo son requeridos',
            ], 422);
        }

        $rows = PlanningCache::where('gestion', $gestion)
            ->where('sede_api_id', $sedeApiId)
            ->where('carrera_codigo', $carreraCodigo)
            ->where('plan_estudios', 'N')
            ->orderBy('semestre')
            ->orderBy('sigla')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'success'         => true,
                'sincronizado'    => false,
                'data'            => [],
                'sincronizado_at' => null,
                'message'         => 'No hay datos sincronizados para esta carrera y gestión. Use "Sincronizar API" primero.',
            ]);
        }

        $resultado = $this->agruparMateriasDesdeBD($rows);

        return response()->json([
            'success'         => true,
            'sincronizado'    => true,
            'data'            => $resultado,
            'total'           => count($resultado),
            'sincronizado_at' => $rows->max('sincronizado_at'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MÉTODOS PRIVADOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sincroniza una carrera individual y devuelve resultado con detalle.
     * NO maneja transacciones (el caller lo hace).
     */
    private function sincronizarUnaCarrera(string $gestion, string $codigoApi, int $sedeApiId, $now): array
    {
        try {
            $rawGrupos = $this->service->listarGrupos($gestion, $codigoApi, $sedeApiId);

            if (empty($rawGrupos)) {
                return [
                    'codigo'     => strtoupper($codigoApi),
                    'status'     => 'sin_datos',
                    'registros'  => 0,
                    'error'      => null,
                    'mensaje'    => 'La API no devolvió datos para esta carrera/gestión',
                ];
            }

            // Filtrar solo Plan N
            $gruposPlanN = array_filter($rawGrupos, fn($m) => ($m['plan_estudios'] ?? 'N') === 'N');

            $insertRows = [];
            foreach ($gruposPlanN as $materia) {
                foreach ($materia['grupos'] as $slot) {
                    $insertRows[] = [
                        'id_horario_api'  => $slot['id_horario'] ?? 0,
                        'id_designacion'  => 0,
                        'docente_nombre'  => $slot['docente'] ?? null,
                        'docente_ci'      => $slot['docente_ci'] ?? null,
                        'sigla'           => $materia['codigo'],
                        'materia'         => $materia['nombre'],
                        'semestre'        => $materia['semestre'],
                        'grupo'           => $slot['grupo'] ?? null,
                        'tipo_clase'      => $slot['tipo_clase'] ?? null,
                        'dia'             => $slot['dia'] ?? null,
                        'hora_inicio'     => $slot['hora_inicio'] ?? null,
                        'hora_fin'        => $slot['hora_fin'] ?? null,
                        'aula'            => $slot['aula'] ?? null,
                        'bloque'          => $slot['bloque'] ?? null,
                        'capacidad_aula'  => $slot['capacidad'] ?? null,
                        'carrera_codigo'  => strtoupper($codigoApi),
                        'sede_api_id'     => $sedeApiId,
                        'sede_nombre'     => $materia['sede_nombre'] ?? 'Cochabamba',
                        'gestion'         => $gestion,
                        'plan_estudios'   => 'N',
                        'sincronizado_at' => $now,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ];
                }
            }

            if (!empty($insertRows)) {
                foreach (array_chunk($insertRows, 500) as $chunk) {
                    PlanningCache::insert($chunk);
                }
            }

            return [
                'codigo'    => strtoupper($codigoApi),
                'status'    => 'ok',
                'registros' => count($insertRows),
                'error'     => null,
                'mensaje'   => count($insertRows) . ' registros guardados',
            ];
        } catch (\Exception $e) {
            Log::error("PlanningCache: Error en carrera {$codigoApi}: " . $e->getMessage());
            return [
                'codigo'    => strtoupper($codigoApi),
                'status'    => 'error',
                'registros' => 0,
                'error'     => $e->getMessage(),
                'mensaje'   => 'Error al conectar con la API',
            ];
        }
    }

    /**
     * Agrupa filas de planning_cache en materias con docentes formateados.
     * Mismo formato que GruposExternoService::transformarMateriasPlanN().
     */
    private function agruparMateriasDesdeBD($rows): array
    {
        $materias    = [];
        $horariosVis = [];

        foreach ($rows as $row) {
            $horarioKey = implode('-', [
                $row->sigla,
                $row->grupo ?? '',
                $row->tipo_clase ?? '',
                $row->dia ?? '',
                $row->hora_inicio ?? '',
                $row->docente_ci ?? '',
            ]);

            if (isset($horariosVis[$horarioKey])) continue;
            $horariosVis[$horarioKey] = true;

            $materiaKey = $row->sigla . '-' . $row->semestre;

            if (!isset($materias[$materiaKey])) {
                $materias[$materiaKey] = [
                    'codigo'          => $row->sigla,
                    'nombre'          => $row->materia,
                    'semestre'        => $row->semestre,
                    'carrera'         => $row->carrera_codigo,
                    'sede_id'         => $row->sede_api_id,
                    'sede_nombre'     => $row->sede_nombre,
                    'gestion'         => $row->gestion,
                    'plan_estudios'   => $row->plan_estudios,
                    'docentes_grupos' => [],
                ];
            }

            $docente = $row->docente_nombre;
            $grupo   = $row->grupo ?? '';
            if ($docente && $grupo !== '') {
                if (!isset($materias[$materiaKey]['docentes_grupos'][$docente])) {
                    $materias[$materiaKey]['docentes_grupos'][$docente] = [];
                }
                if (!in_array($grupo, $materias[$materiaKey]['docentes_grupos'][$docente])) {
                    $materias[$materiaKey]['docentes_grupos'][$docente][] = $grupo;
                }
            }
        }

        $resultado = [];
        foreach ($materias as $materia) {
            $docentesFormateados = [];
            foreach ($materia['docentes_grupos'] as $docente => $grupos) {
                $docentesFormateados[] = empty($grupos)
                    ? $docente
                    : $docente . ' (' . implode(', ', $grupos) . ')';
            }

            $resultado[] = [
                'codigo'          => $materia['codigo'],
                'nombre'          => $materia['nombre'],
                'semestre'        => $materia['semestre'],
                'carrera'         => $materia['carrera'],
                'sede_id'         => $materia['sede_id'],
                'sede_nombre'     => $materia['sede_nombre'],
                'gestion'         => $materia['gestion'],
                'plan_estudios'   => $materia['plan_estudios'],
                'docentes'        => $docentesFormateados,
                'docentes_string' => implode(', ', $docentesFormateados),
            ];
        }

        usort($resultado, function ($a, $b) {
            if ($a['semestre'] !== $b['semestre']) return $a['semestre'] - $b['semestre'];
            return strcmp($a['codigo'], $b['codigo']);
        });

        return $resultado;
    }
}
