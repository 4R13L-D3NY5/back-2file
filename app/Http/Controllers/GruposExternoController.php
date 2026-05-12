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
     * Obtener materias de un plan específico (N o A) desde la API externa
     */
    public function planN(Request $request): JsonResponse
    {
        $gestion = $request->input('gestion', '1-2026');
        $carrera = $request->input('carrera', 'carsis');
        $sede = (int) $request->input('sede', 1);
        $plan = $request->input('plan_estudios', 'N'); // N = Nueva, A = Antigua

        $data = $this->service->listarMateriasPlan($gestion, $carrera, $sede, $plan);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'gestion' => $gestion,
                'carrera' => strtoupper($carrera),
                'sede' => $sede,
                'plan_estudios' => $plan,
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
                ->where('grupos.estado', 'ACTIVO')
                ->whereNull('grupos.deleted_at')
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
     * Buscar carpeta (asignatura local) por código y plan_estudios = N
     * Retorna todas las coincidencias con su info detallada: unidades, temas, cronogramas
     */
    public function buscarCarpeta(Request $request): JsonResponse
    {
        $codigo = $request->input('codigo');

        if (!$codigo) {
            return response()->json([
                'success' => false,
                'message' => 'El código de asignatura es requerido'
            ], 400);
        }

        $asignaturas = Asignatura::withTrashed()
            ->where('codigo', $codigo)
            ->where('plan_estudios', 'N')
            ->with([
                'carreras',
                'unidades' => function ($q) {
                    // Unidad no usa SoftDeletes, no llamar withTrashed()
                    $q->orderBy('orden')->with(['temas' => function ($qt) {
                        $qt->orderBy('orden')->select('id', 'unidad_id', 'titulo');
                    }]);
                },
                'grupos' => function ($q) {
                    // withTrashed + withoutGlobalScope: buscarCarpeta necesita ver TODOS los grupos
                    $q->withoutGlobalScope('activo')->withTrashed()->with('docente');
                },
                'cronogramas' => function ($q) {
                    $q->select('id', 'asignatura_id', 'fecha', 'tema_ejecutado')->limit(10);
                },
            ])
            ->get();

        if ($asignaturas->isEmpty()) {
            return response()->json([
                'success' => true,
                'encontrado' => false,
                'data' => [],
                'message' => 'No se encontró ninguna carpeta con ese código y plan N'
            ]);
        }

        $data = $asignaturas->map(function ($asig) {
            $totalTemas = $asig->unidades->sum(fn($u) => $u->temas->count());
            $totalCronogramas = $asig->cronogramas->count();
            $docentes = $asig->grupos
                ->filter(fn($g) => $g->docente)
                ->map(fn($g) => [
                    'id' => $g->docente->id,
                    'nombre_completo' => $g->docente->nombre_completo,
                    'grupo' => $g->nombre,
                ])
                ->unique('id')
                ->values();

            return [
                'id' => $asig->id,
                'codigo' => $asig->codigo,
                'nombre' => $asig->nombre,
                'plan_estudios' => $asig->plan_estudios,
                'eliminada' => !is_null($asig->deleted_at),
                'deleted_at' => $asig->deleted_at,
                'estado' => $asig->estado ?? null,
                'creditos' => $asig->creditos,
                'horas_teoricas' => $asig->horas_teoricas,
                'horas_practicas' => $asig->horas_practicas,
                'carreras' => $asig->carreras->map(fn($c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'semestre' => $c->pivot->semestre ?? null,
                ]),
                'unidades_count' => $asig->unidades->count(),
                'temas_count' => $totalTemas,
                'cronogramas_count' => $totalCronogramas,
                'docentes_count' => $docentes->count(),
                'docentes' => $docentes,
                'unidades' => $asig->unidades->map(fn($u) => [
                    'id' => $u->id,
                    'titulo' => $u->titulo ?? $u->nombre,
                    'temas_count' => $u->temas->count(),
                    'temas' => $u->temas->map(fn($t) => [
                        'id' => $t->id,
                        'titulo' => $t->titulo,
                    ]),
                ]),
            ];
        });

        return response()->json([
            'success' => true,
            'encontrado' => true,
            'total' => $asignaturas->count(),
            'data' => $data,
        ]);
    }

    /**
     * Obtener detalle completo de grupos y horarios desde la API de Planning
     * para una asignatura específica
     */
    public function detalleConHorarios(Request $request): JsonResponse
    {
        $gestion = $request->input('gestion', '1-2026');
        $carrera = $request->input('carrera', 'carsis');
        $sede    = (int) $request->input('sede', 1);
        $codigo  = $request->input('codigo');

        if (!$codigo) {
            return response()->json([
                'success' => false,
                'message' => 'El código de asignatura es requerido'
            ], 400);
        }

        // Convertir sede: si llega id_api buscar el sede interno
        $sedeModel = \App\Models\Sede::where('id_api', $sede)->first()
            ?? \App\Models\Sede::find($sede);

        // Convertir carrera: código API a modelo local
        $carreraModel = is_numeric($carrera)
            ? \App\Models\Carrera::find($carrera)
            : \App\Models\Carrera::where('codigo', $carrera)->first();

        // Obtener todos los grupos/horarios crudos desde el servicio (transformarDatos)
        $rawGrupos = $this->service->listarGrupos($gestion, $carrera, $sede);

        // Filtrar la asignatura específica
        $materiaEncontrada = null;
        foreach ($rawGrupos as $materia) {
            if (trim($materia['codigo']) === trim($codigo)) {
                $materiaEncontrada = $materia;
                break;
            }
        }

        if (!$materiaEncontrada) {
            return response()->json([
                'success' => true,
                'encontrado' => false,
                'data' => null,
                'message' => 'No se encontró la asignatura en la API de Planning para estos parámetros'
            ]);
        }

        // Agrupar horarios por docente+grupo
        $docentesGrupos = [];
        foreach ($materiaEncontrada['grupos'] as $slot) {
            $docenteNombre = $slot['docente'] ?? 'Sin docente';
            $docenteCI     = $slot['docente_ci'] ?? null;
            $grupoNombre   = (string) ($slot['grupo'] ?? '');
            $key           = $docenteNombre . '|||' . $grupoNombre;

            if (!isset($docentesGrupos[$key])) {
                // Verificar si el docente ya existe localmente
                $docenteLocal = null;
                if ($docenteNombre !== 'Sin docente') {
                    // Buscar primero por CI, luego por nombre
                    if ($docenteCI) {
                        $docenteLocal = \App\Models\Docente::where('ci', $docenteCI)->first();
                    }
                    if (!$docenteLocal) {
                        $docenteLocal = \App\Models\Docente::where('nombre_completo', 'like', '%' . $docenteNombre . '%')
                            ->when($sedeModel, fn($q) => $q->where('sede_id', $sedeModel->id))
                            ->first();
                    }
                }

                $docentesGrupos[$key] = [
                    'docente_nombre' => $docenteNombre,
                    'docente_ci'     => $docenteCI,
                    'grupo_nombre'   => $grupoNombre,
                    'existe_local'   => !is_null($docenteLocal),
                    'docente_local_id' => $docenteLocal?->id,
                    'docente_local_nombre' => $docenteLocal?->nombre_completo,
                    'horarios'       => [],
                ];
            }

            $docentesGrupos[$key]['horarios'][] = [
                'id_horario_api' => $slot['id_horario'],
                'tipo_clase'     => $slot['tipo_clase'],
                'dia'            => $slot['dia'],
                'hora_inicio'    => $slot['hora_inicio'],
                'hora_fin'       => $slot['hora_fin'],
                'aula'           => $slot['aula'],
                'bloque'         => $slot['bloque'],
            ];
        }

        return response()->json([
            'success'    => true,
            'encontrado' => true,
            'asignatura' => [
                'codigo'  => $materiaEncontrada['codigo'],
                'nombre'  => $materiaEncontrada['nombre'],
                'semestre' => $materiaEncontrada['semestre'],
                'plan_estudios' => $materiaEncontrada['plan_estudios'],
            ],
            'sede_id'    => $sedeModel?->id,
            'carrera_id' => $carreraModel?->id,
            'data'       => array_values($docentesGrupos),
        ]);
    }

    /**
     * Importar docentes, grupos y horarios desde la API de Planning al sistema local
     */
    public function importarDesdePlanning(Request $request): JsonResponse
    {
        $request->validate([
            'asignatura_id' => 'required|integer|exists:asignaturas,id',
            'sede_id'       => 'required|integer|exists:sedes,id',
            'carrera_id'    => 'required|integer|exists:carreras,id',
            'gestion'       => 'required|string',
            'items'         => 'required|array|min:1',
            'items.*.docente_nombre' => 'required|string',
            'items.*.docente_ci'     => 'nullable|string',
            'items.*.grupo_nombre'   => 'required|string',
            'items.*.horarios'       => 'required|array|min:1',
            'items.*.horarios.*.id_horario_api' => 'required|integer',
            'items.*.horarios.*.tipo_clase'     => 'required|string',
            'items.*.horarios.*.dia'            => 'required|string',
            'items.*.horarios.*.hora_inicio'    => 'required|string',
            'items.*.horarios.*.hora_fin'       => 'required|string',
            'items.*.horarios.*.aula'           => 'nullable|string',
            'items.*.horarios.*.bloque'         => 'nullable|string',
        ]);

        $asignaturaId = $request->asignatura_id;
        $sedeId       = $request->sede_id;
        $carreraId    = $request->carrera_id;
        $gestion      = $request->gestion;

        $results = [
            'docentes_creados' => 0,
            'docentes_existentes' => 0,
            'grupos_creados' => 0,
            'grupos_actualizados' => 0,
            'horarios_creados' => 0,
            'errores' => [],
        ];

        DB::beginTransaction();
        try {
            foreach ($request->items as $idx => $item) {
                // 1. Buscar o crear docente
                $docente = null;
                if (!empty($item['docente_ci'])) {
                    $docente = \App\Models\Docente::where('ci', $item['docente_ci'])->first();
                }
                if (!$docente) {
                    $docente = \App\Models\Docente::where('nombre_completo', 'like', '%' . $item['docente_nombre'] . '%')
                        ->where('sede_id', $sedeId)
                        ->first();
                }

                if ($docente) {
                    $results['docentes_existentes']++;
                } else {
                    // Crear nuevo docente con datos mínimos
                    $docente = \App\Models\Docente::create([
                        'nombre_completo' => $item['docente_nombre'],
                        'ci'              => $item['docente_ci'] ?? null,
                        'sede_id'         => $sedeId,
                        'estado'          => true,
                    ]);
                    $results['docentes_creados']++;
                }

                // 2. Buscar o crear grupo
                // withoutGlobalScope: el import necesita encontrar grupos existentes sin importar estado
                $grupo = \App\Models\Grupo::withoutGlobalScope('activo')
                    ->where('asignatura_id', $asignaturaId)
                    ->where('carrera_id', $carreraId)
                    ->where('sede_id', $sedeId)
                    ->where('nombre', $item['grupo_nombre'])
                    ->first();

                if ($grupo) {
                    // Actualizar docente asignado (puede ser null o diferente)
                    $grupo->docente_id = $docente->id;
                    $grupo->save();
                    $results['grupos_actualizados']++;
                } else {
                    // Crear nuevo grupo
                    $grupo = \App\Models\Grupo::create([
                        'asignatura_id' => $asignaturaId,
                        'carrera_id'    => $carreraId,
                        'sede_id'       => $sedeId,
                        'docente_id'    => $docente->id,
                        'nombre'        => $item['grupo_nombre'],
                        'gestion'       => $gestion,
                        'plan_estudios' => 'N',
                        'tipo'          => $this->inferirTipoGrupo($item['horarios']),
                        'turno'         => $this->inferirTurno($item['horarios']),
                        'estado'        => true,
                    ]);
                    $results['grupos_creados']++;
                }

                // 3. Crear horarios (eliminar existentes y crear nuevos)
                \App\Models\Horario::where('grupo_id', $grupo->id)->delete();
                foreach ($item['horarios'] as $horarioData) {
                    \App\Models\Horario::create([
                        'id_horario_api' => $horarioData['id_horario_api'],
                        'grupo_id'       => $grupo->id,
                        'dia'            => $horarioData['dia'],
                        'hora_inicio'    => $horarioData['hora_inicio'],
                        'hora_fin'       => $horarioData['hora_fin'],
                    ]);
                    $results['horarios_creados']++;
                }
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Importación completada exitosamente',
                'data'    => $results,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error importando desde Planning', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al importar datos: ' . $e->getMessage(),
                'data'    => null,
            ], 500);
        }
    }

    /**
     * Inferir tipo de grupo (TEORIA, PRACTICA, LABORATORIO) a partir de horarios
     */
    private function inferirTipoGrupo(array $horarios): string
    {
        $tipos = array_map(fn($h) => strtoupper($h['tipo_clase']), $horarios);
        if (in_array('PRACTICA', $tipos)) return 'PRACTICA';
        if (in_array('LABORATORIO', $tipos)) return 'LABORATORIO';
        return 'TEORIA';
    }

    /**
     * Inferir turno (MAÑANA, TARDE, NOCHE) a partir de horarios
     */
    private function inferirTurno(array $horarios): string
    {
        $totalHoras = 0;
        $count = 0;
        foreach ($horarios as $h) {
            $hora = substr($h['hora_inicio'], 0, 2);
            if (is_numeric($hora)) {
                $totalHoras += (int) $hora;
                $count++;
            }
        }
        if ($count === 0) return 'MAÑANA';
        $promedio = $totalHoras / $count;
        if ($promedio < 12) return 'MAÑANA';
        if ($promedio < 18) return 'TARDE';
        return 'NOCHE';
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
