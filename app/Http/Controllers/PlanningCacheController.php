<?php

namespace App\Http\Controllers;

use App\Models\Carrera;
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

    public function __construct(GruposExternoService $service)
    {
        $this->service = $service;
    }

    /**
     * POST /api/planning/sincronizar-cochabamba
     *
     * Sincroniza TODAS las carreras de Cochabamba (sede_api_id=1) para la
     * gestión indicada, Plan N, y guarda en la tabla planning_cache.
     *
     * Body: { "gestion": "1-2026" }
     */
    public function sincronizarCochabamba(Request $request): JsonResponse
    {
        $request->validate([
            'gestion' => 'required|string|max:20',
        ]);

        $gestion    = trim($request->input('gestion'));
        $sedeApiId  = 1; // Cochabamba siempre
        $userId     = Auth::id();

        // Obtener todas las carreras de Cochabamba que tengan código API
        $carreras = Carrera::whereNotNull('codigo')
            ->where('codigo', '!=', '')
            ->where(function ($q) {
                $q->where('sede_id', 1) // sede interna Cochabamba
                  ->orWhereHas('sedes', fn($s) => $s->where('sedes.id', 1));
            })
            ->get(['id', 'nombre', 'codigo']);

        if ($carreras->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron carreras con código API configurado para Cochabamba.',
            ], 422);
        }

        // Crear log de sincronización
        $syncLog = PlanningSyncLog::create([
            'gestion'        => $gestion,
            'sede_api_id'    => $sedeApiId,
            'carrera_codigo' => null, // null = todas
            'user_id'        => $userId,
            'status'         => 'running',
            'started_at'     => now(),
        ]);

        $totalRegistros  = 0;
        $totalCarreras   = 0;
        $errores         = [];
        $now             = now();

        DB::beginTransaction();
        try {
            // Borrar cache anterior para esta gestión+sede antes de repoblar
            PlanningCache::where('gestion', $gestion)
                ->where('sede_api_id', $sedeApiId)
                ->where('plan_estudios', 'N')
                ->delete();

            foreach ($carreras as $carrera) {
                $codigoApi = strtolower(trim($carrera->codigo));

                try {
                    Log::info("PlanningCache: Sincronizando carrera {$codigoApi} - {$gestion}");

                    // Llamar a la API externa a través del servicio existente
                    $rawData = $this->service->listarMateriasPlanN($gestion, $codigoApi, $sedeApiId);

                    if (empty($rawData)) {
                        Log::warning("PlanningCache: Sin datos para {$codigoApi}");
                        continue;
                    }

                    // Insertar cada registro de materia en planning_cache
                    // rawData es array de materias aplanadas (una por sigla+semestre)
                    // Necesitamos el detalle de grupos/horarios para guardar filas individuales
                    $rawGrupos = $this->service->listarGrupos($gestion, $codigoApi, $sedeApiId);

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
                        // Insertar en chunks de 500
                        foreach (array_chunk($insertRows, 500) as $chunk) {
                            PlanningCache::insert($chunk);
                        }
                        $totalRegistros += count($insertRows);
                    }

                    $totalCarreras++;
                } catch (\Exception $e) {
                    Log::error("PlanningCache: Error en carrera {$codigoApi}: " . $e->getMessage());
                    $errores[] = "Carrera {$codigoApi}: " . $e->getMessage();
                }
            }

            DB::commit();

            // Actualizar log como completado
            $syncLog->update([
                'status'          => 'completed',
                'total_registros' => $totalRegistros,
                'total_carreras'  => $totalCarreras,
                'finished_at'     => now(),
                'error_message'   => empty($errores) ? null : implode("\n", $errores),
            ]);

            return response()->json([
                'success'         => true,
                'message'         => "Sincronización completada: {$totalCarreras} carreras, {$totalRegistros} registros guardados.",
                'total_carreras'  => $totalCarreras,
                'total_registros' => $totalRegistros,
                'gestion'         => $gestion,
                'errores'         => $errores,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PlanningCache: Error general en sincronización: ' . $e->getMessage());

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

    /**
     * GET /api/planning/cache
     *
     * Lee los datos guardados en planning_cache para una carrera y gestión dada.
     * Agrupa por sigla+semestre y devuelve materias con sus docentes/grupos.
     *
     * Query params:
     *   - gestion: string (ej: "1-2026")
     *   - carrera_codigo: string (ej: "CARENL" o "carenl")
     *   - sede_api_id: int (default 1)
     */
    public function getCache(Request $request): JsonResponse
    {
        $gestion      = $request->input('gestion');
        $carreraCodigo = strtoupper(trim($request->input('carrera_codigo', '')));
        $sedeApiId    = (int) $request->input('sede_api_id', 1);

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
                'success'        => true,
                'sincronizado'   => false,
                'data'           => [],
                'sincronizado_at' => null,
                'message'        => 'No hay datos sincronizados para esta carrera y gestión. Use "Sincronizar API" primero.',
            ]);
        }

        // Agrupar por sigla+semestre (igual que GruposExternoService)
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

            if (isset($horariosVis[$horarioKey])) {
                continue;
            }
            $horariosVis[$horarioKey] = true;

            $materiaKey = $row->sigla . '-' . $row->semestre;

            if (!isset($materias[$materiaKey])) {
                $materias[$materiaKey] = [
                    'codigo'       => $row->sigla,
                    'nombre'       => $row->materia,
                    'semestre'     => $row->semestre,
                    'carrera'      => $row->carrera_codigo,
                    'sede_id'      => $row->sede_api_id,
                    'sede_nombre'  => $row->sede_nombre,
                    'gestion'      => $row->gestion,
                    'plan_estudios' => $row->plan_estudios,
                    'docentes_grupos' => [], // docente => grupos[]
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

        // Formatear resultado igual que GruposExternoService::transformarMateriasPlanN
        $resultado = [];
        foreach ($materias as $materia) {
            $docentesFormateados = [];
            foreach ($materia['docentes_grupos'] as $docente => $grupos) {
                if (empty($grupos)) {
                    $docentesFormateados[] = $docente;
                } else {
                    $docentesFormateados[] = $docente . ' (' . implode(', ', $grupos) . ')';
                }
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
            if ($a['semestre'] !== $b['semestre']) {
                return $a['semestre'] - $b['semestre'];
            }
            return strcmp($a['codigo'], $b['codigo']);
        });

        $sincronizadoAt = $rows->max('sincronizado_at');

        return response()->json([
            'success'        => true,
            'sincronizado'   => true,
            'data'           => $resultado,
            'total'          => count($resultado),
            'sincronizado_at' => $sincronizadoAt,
        ]);
    }

    /**
     * GET /api/planning/sync-status
     *
     * Devuelve el último log de sincronización para Cochabamba.
     */
    public function syncStatus(Request $request): JsonResponse
    {
        $gestion   = $request->input('gestion');
        $sedeApiId = 1;

        $query = PlanningSyncLog::where('sede_api_id', $sedeApiId)
            ->whereNull('carrera_codigo') // null = todas las carreras
            ->orderByDesc('id');

        if ($gestion) {
            $query->where('gestion', $gestion);
        }

        $log = $query->first();

        if (!$log) {
            return response()->json([
                'success'        => true,
                'sincronizado'   => false,
                'ultimo_sync'    => null,
                'message'        => 'Nunca se ha sincronizado.',
            ]);
        }

        // Contar registros guardados
        $totalRegistros = PlanningCache::where('sede_api_id', $sedeApiId)
            ->when($gestion, fn($q) => $q->where('gestion', $gestion))
            ->count();

        return response()->json([
            'success'          => true,
            'sincronizado'     => $log->status === 'completed',
            'ultimo_sync'      => [
                'id'              => $log->id,
                'gestion'         => $log->gestion,
                'status'          => $log->status,
                'total_registros' => $log->total_registros,
                'total_carreras'  => $log->total_carreras,
                'started_at'      => $log->started_at,
                'finished_at'     => $log->finished_at,
                'error_message'   => $log->error_message,
            ],
            'registros_en_bd'  => $totalRegistros,
        ]);
    }
}
