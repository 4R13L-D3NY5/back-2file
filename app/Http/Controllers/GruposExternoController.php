<?php

namespace App\Http\Controllers;

use App\Services\GruposExternoService;
use App\Models\Asignatura;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GruposExternoController extends Controller
{
    protected GruposExternoService $service;

    public function __construct(GruposExternoService $service)
    {
        $this->service = $service;
    }

    /**
     * Listar grupos desde la API externa
     *
     * Query params:
     * - gestion: string (ej: "1-2026")
     * - carrera: string (ej: "carsis")
     * - sede: int (ej: 1)
     */
    public function index(Request $request): JsonResponse
    {
        $gestion = $request->input('gestion', '1-2026');
        $carrera = $request->input('carrera', 'carsis');
        $sede = (int) $request->input('sede', 1);

        $data = $this->service->listarGrupos($gestion, $carrera, $sede);

        // Calcular estadísticas
        $totalGrupos = 0;
        $totalDocentes = [];
        foreach ($data as $materia) {
            $totalGrupos += count($materia['grupos']);
            foreach ($materia['grupos'] as $grupo) {
                if (!empty($grupo['docente_ci'])) {
                    $totalDocentes[$grupo['docente_ci']] = $grupo['docente'];
                }
            }
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'gestion' => $gestion,
                'carrera' => strtoupper($carrera),
                'sede' => $sede,
                'total_materias' => count($data),
                'total_grupos' => $totalGrupos,
                'total_docentes' => count($totalDocentes)
            ]
        ]);
    }

    /**
     * Limpiar cache y recargar datos
     */
    public function refresh(Request $request): JsonResponse
    {
        $gestion = $request->input('gestion', '1-2026');
        $carrera = $request->input('carrera', 'carsis');
        $sede = (int) $request->input('sede', 1);

        $this->service->limpiarCache($gestion, $carrera, $sede);
        $data = $this->service->listarGrupos($gestion, $carrera, $sede);

        return response()->json([
            'message' => 'Cache actualizado',
            'data' => $data,
            'meta' => [
                'total_materias' => count($data)
            ]
        ]);
    }

    /**
     * Obtener materias del Plan N (API externa)
     */
    public function planN(Request $request): JsonResponse
    {
        $gestion = $request->input('gestion', '1-2026');
        $carrera = $request->input('carrera', 'carsis');
        $sede = (int) $request->input('sede', 1);

        $data = $this->service->listarMateriasPlanN($gestion, $carrera, $sede);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'gestion' => $gestion,
                'carrera' => strtoupper($carrera),
                'sede' => $sede,
                'total_materias' => count($data)
            ]
        ]);
    }

    /**
     * Comparar asignatura específica entre API externa y datos locales
     *
     * Query params:
     * - gestion: string (ej: "1-2026")
     * - carrera: string (ej: "carsis")
     * - sede: int (ej: 1)
     * - codigo: string (código de asignatura)
     */
    public function compararAsignatura(Request $request): JsonResponse
    {
        $gestion = $request->input('gestion', '1-2026');
        $carrera = $request->input('carrera', 'carsis');
        $sede = (int) $request->input('sede', 1);
        $codigo = $request->input('codigo');

        Log::debug('GruposExternoController.compararAsignatura - Parámetros recibidos', [
            'gestion' => $gestion,
            'carrera' => $carrera,
            'sede' => $sede,
            'codigo' => $codigo,
        ]);

        if (!$codigo) {
            return response()->json([
                'success' => false,
                'message' => 'El código de asignatura es requerido'
            ], 400);
        }

        // Obtener datos de API externa
        $apiData = $this->service->obtenerAsignaturaDetalle($gestion, $carrera, $sede, $codigo);

        // Buscar asignatura local por código (considerando plan de estudios N)
        // Primero determinar el ID de sede interno (puede ser API ID o interno)
        $sedeModel = null;
        if ($sede) {
            // Intentar encontrar por id_api primero (API externa), luego por id
            $sedeModel = \App\Models\Sede::where('id_api', $sede)->first();
            if (!$sedeModel) {
                $sedeModel = \App\Models\Sede::find($sede);
            }
            Log::debug('GruposExternoController.compararAsignatura - Sede encontrada', [
                'sede_input' => $sede,
                'sede_model' => $sedeModel ? [
                    'id' => $sedeModel->id,
                    'id_api' => $sedeModel->id_api,
                    'nombre' => $sedeModel->nombre
                ] : null,
            ]);
        }
        
        $carreraModel = null;
        if ($carrera) {
            // $carrera puede ser código API (string) o ID interno (numérico)
            if (is_numeric($carrera)) {
                $carreraModel = \App\Models\Carrera::find($carrera);
            } else {
                $carreraModel = \App\Models\Carrera::where('codigo', $carrera)->first();
            }
            Log::debug('GruposExternoController.compararAsignatura - Carrera encontrada', [
                'carrera_input' => $carrera,
                'carrera_model' => $carreraModel ? [
                    'id' => $carreraModel->id,
                    'codigo' => $carreraModel->codigo,
                    'nombre' => $carreraModel->nombre
                ] : null,
            ]);
        }
        
        // Consulta alternativa simple para depuración
        Log::debug('GruposExternoController.compararAsignatura - Consulta SQL alternativa', [
            'sql' => Asignatura::where('codigo', $codigo)
                ->where('plan_estudios', 'N')
                ->toSql(),
        ]);
        
        // Consulta de depuración similar a la SQL del usuario
        if ($sedeModel) {
            $depuracionResultados = DB::table('docentes')
                ->join('grupos', 'docentes.id', '=', 'grupos.docente_id')
                ->join('asignaturas', 'asignaturas.id', '=', 'grupos.asignatura_id')
                ->select('grupos.nombre as grupo_nombre', 'asignaturas.id as asignatura_id', 'docentes.*')
                ->where('asignaturas.codigo', $codigo)
                ->where('asignaturas.plan_estudios', 'N')
                ->where('docentes.sede_id', $sedeModel->id)
                ->get();
                
            Log::debug('GruposExternoController.compararAsignatura - Consulta SQL directa (depuración)', [
                'resultados_count' => $depuracionResultados->count(),
                'resultados' => $depuracionResultados->toArray(),
            ]);
        }
        
        // Consulta simplificada similar a la SQL que funciona
        // Primero solo filtramos por docente.sede_id (como en la consulta SQL)
        $asignaturaLocal = Asignatura::where('codigo', $codigo)
            ->where('plan_estudios', 'N')
            ->with(['grupos' => function ($q) use ($sedeModel, $carreraModel) {
                // NO filtramos por grupos.sede_id (omitido temporalmente)
                // En su lugar, filtramos por docente.sede_id usando whereHas
                if ($sedeModel) {
                    $q->whereHas('docente', function ($subQuery) use ($sedeModel) {
                        $subQuery->where('sede_id', $sedeModel->id);
                    });
                }
                
                // Mantenemos filtro de carrera si existe
                if ($carreraModel) {
                    $q->where('carrera_id', $carreraModel->id);
                }
                // Si no hay carreraModel, no filtramos (puede ser null o cualquier valor)
                
                $q->with('docente');
            }])
            ->first();

        Log::debug('GruposExternoController.compararAsignatura - Asignatura local encontrada', [
            'asignatura' => $asignaturaLocal ? [
                'id' => $asignaturaLocal->id,
                'codigo' => $asignaturaLocal->codigo,
                'nombre' => $asignaturaLocal->nombre,
                'plan_estudios' => $asignaturaLocal->plan_estudios,
                'grupos_count' => $asignaturaLocal->grupos ? count($asignaturaLocal->grupos) : 0,
            ] : null,
        ]);

        if ($asignaturaLocal && $asignaturaLocal->grupos) {
            Log::debug('GruposExternoController.compararAsignatura - Grupos detallados', [
                'grupos' => $asignaturaLocal->grupos->map(function ($grupo) {
                    return [
                        'id' => $grupo->id,
                        'nombre' => $grupo->nombre,
                        'tipo' => $grupo->tipo,
                        'turno' => $grupo->turno,
                        'sede_id' => $grupo->sede_id,
                        'carrera_id' => $grupo->carrera_id,
                        'docente_id' => $grupo->docente_id,
                        'docente' => $grupo->docente ? [
                            'id' => $grupo->docente->id,
                            'nombre' => $grupo->docente->nombre,
                            'nombre_completo' => $grupo->docente->nombre_completo,
                        ] : null,
                    ];
                })->toArray(),
            ]);
        }

        $localData = null;
        if ($asignaturaLocal) {
            // Formatear datos locales similares a API
            $docentes = $this->extraerDocentesDeAsignatura($asignaturaLocal);
            $grupos = $this->extraerGruposDeAsignatura($asignaturaLocal);
            
            Log::debug('GruposExternoController.compararAsignatura - Datos extraídos', [
                'docentes_count' => count($docentes),
                'docentes' => $docentes,
                'grupos_count' => count($grupos),
            ]);
            
            $localData = [
                'codigo' => $asignaturaLocal->codigo,
                'nombre' => $asignaturaLocal->nombre,
                'semestre' => $asignaturaLocal->semestre,
                'plan_estudios' => $asignaturaLocal->plan_estudios,
                'docentes' => $docentes,
                'grupos' => $grupos
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'api' => $apiData,
                'local' => $localData,
                'comparacion' => [
                    'existe_en_api' => !is_null($apiData),
                    'existe_en_local' => !is_null($localData),
                    'coincidencia_nombre' => $apiData && $localData ? $apiData['nombre'] === $localData['nombre'] : false,
                    'coincidencia_semestre' => $apiData && $localData ? $apiData['semestre'] == $localData['semestre'] : false
                ]
            ],
            'meta' => [
                'gestion' => $gestion,
                'carrera' => strtoupper($carrera),
                'sede' => $sede,
                'codigo' => $codigo
            ]
        ]);
    }

    /**
     * Extraer docentes de asignatura local (con grupos)
     * Solo considera docentes asignados a grupos de esta asignatura
     */
    private function extraerDocentesDeAsignatura(Asignatura $asignatura): array
    {
        $docentesMap = [];

        Log::debug('GruposExternoController.extraerDocentesDeAsignatura - Iniciando', [
            'asignatura_id' => $asignatura->id,
            'grupos_count' => $asignatura->grupos ? count($asignatura->grupos) : 0,
        ]);

        // Solo docentes de grupos (omitir docentes directos sin grupos para esta vista)
        foreach ($asignatura->grupos as $grupo) {
            Log::debug('GruposExternoController.extraerDocentesDeAsignatura - Procesando grupo', [
                'grupo_id' => $grupo->id,
                'grupo_nombre' => $grupo->nombre,
                'grupo_tipo' => $grupo->tipo,
                'grupo_turno' => $grupo->turno,
                'docente_id' => $grupo->docente_id,
                'docente_loaded' => isset($grupo->docente),
                'docente' => $grupo->docente ? [
                    'id' => $grupo->docente->id,
                    'nombre' => $grupo->docente->nombre,
                    'nombre_completo' => $grupo->docente->nombre_completo,
                ] : null,
            ]);
            
            if ($grupo->docente) {
                $nombre = $grupo->docente->nombre_completo ?? $grupo->docente->nombre ?? 'Sin nombre';
                $grupoNombre = $grupo->nombre ?? $grupo->tipo ?? $grupo->turno ?? 'Sin grupo';
                
                Log::debug('GruposExternoController.extraerDocentesDeAsignatura - Datos extraídos', [
                    'nombre' => $nombre,
                    'grupoNombre' => $grupoNombre,
                ]);
                
                if (!isset($docentesMap[$nombre])) {
                    $docentesMap[$nombre] = [
                        'docente' => $nombre,
                        'docente_id' => $grupo->docente->id,
                        'grupos' => []
                    ];
                    Log::debug('GruposExternoController.extraerDocentesDeAsignatura - Nuevo docente agregado', [
                        'nombre' => $nombre,
                    ]);
                }
                
                if ($grupoNombre) {
                    $grupoExistente = collect($docentesMap[$nombre]['grupos'])->firstWhere('id', $grupo->id);
                    if (!$grupoExistente) {
                        $docentesMap[$nombre]['grupos'][] = [
                            'id' => $grupo->id,
                            'nombre' => $grupoNombre
                        ];
                        Log::debug('GruposExternoController.extraerDocentesDeAsignatura - Grupo agregado a docente', [
                            'nombre' => $nombre,
                            'grupo' => $grupoNombre,
                            'grupo_id' => $grupo->id,
                        ]);
                    }
                }
            } else {
                Log::debug('GruposExternoController.extraerDocentesDeAsignatura - Grupo sin docente asignado', [
                    'grupo_id' => $grupo->id,
                ]);
            }
        }

        Log::debug('GruposExternoController.extraerDocentesDeAsignatura - Resultado final', [
            'docentes_count' => count($docentesMap),
            'docentes' => array_values($docentesMap),
        ]);

        return array_values($docentesMap);
    }

    /**
     * Extraer grupos de asignatura local
     */
    private function extraerGruposDeAsignatura(Asignatura $asignatura): array
    {
        $grupos = [];
        
        foreach ($asignatura->grupos as $grupo) {
            $grupos[] = [
                'id' => $grupo->id,
                'nombre' => $grupo->nombre,
                'tipo' => $grupo->tipo,
                'turno' => $grupo->turno,
                'docente' => $grupo->docente ? [
                    'nombre' => $grupo->docente->nombre_completo ?? $grupo->docente->nombre ?? 'Sin nombre',
                    'ci' => $grupo->docente->ci ?? null
                ] : null
            ];
        }
        
        return $grupos;
    }

    /**
     * Quitar grupo a docente (remover asignación)
     */
    public function quitarGrupoDocente(Request $request): JsonResponse
    {
        $request->validate([
            'grupo_id' => 'required|integer|exists:grupos,id',
        ]);

        $grupo = \App\Models\Grupo::find($request->grupo_id);
        
        if (!$grupo) {
            return response()->json([
                'success' => false,
                'message' => 'Grupo no encontrado'
            ], 404);
        }

        $grupo->docente_id = null;
        $grupo->save();

        Log::debug('GruposExternoController.quitarGrupoDocente - Grupo actualizado', [
            'grupo_id' => $grupo->id,
            'docente_id' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Docente removido del grupo exitosamente',
            'data' => [
                'grupo_id' => $grupo->id,
                'docente_id' => null,
            ]
        ]);
    }

    /**
     * Dar grupo a docente (asignar docente a grupo)
     */
    public function asignarGrupoDocente(Request $request): JsonResponse
    {
        $request->validate([
            'grupo_id' => 'required|integer|exists:grupos,id',
            'docente_id' => 'required|integer|exists:docentes,id',
        ]);

        $grupo = \App\Models\Grupo::find($request->grupo_id);
        $docente = \App\Models\Docente::find($request->docente_id);
        
        if (!$grupo) {
            return response()->json([
                'success' => false,
                'message' => 'Grupo no encontrado'
            ], 404);
        }

        if (!$docente) {
            return response()->json([
                'success' => false,
                'message' => 'Docente no encontrado'
            ], 404);
        }

        $grupo->docente_id = $docente->id;
        $grupo->save();

        Log::debug('GruposExternoController.asignarGrupoDocente - Grupo actualizado', [
            'grupo_id' => $grupo->id,
            'docente_id' => $docente->id,
            'docente_nombre' => $docente->nombre_completo,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Docente asignado al grupo exitosamente',
            'data' => [
                'grupo_id' => $grupo->id,
                'docente_id' => $docente->id,
                'docente_nombre' => $docente->nombre_completo,
            ]
        ]);
    }
}
