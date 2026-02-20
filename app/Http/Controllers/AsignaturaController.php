<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Carrera;
use App\Services\University\UniversityService;
use App\Services\MateriasComunesSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\DocumentParserService;
use App\Services\PlanClaseParserService;
use App\Services\CronogramaParserService;

class AsignaturaController extends Controller
{
    protected $universityService;
    protected $parser; // Added
    protected $planClaseParser; // Added
    protected $cronogramaParser; // Added
    protected $syncService;
    protected MateriasComunesSyncService $materiasComunesSyncService;

    public function __construct(
        UniversityService $universityService,
        DocumentParserService $parser, // Added
        PlanClaseParserService $planClaseParser, // Added
        CronogramaParserService $cronogramaParser, // Added
        \App\Services\AsignaturaSyncService $syncService,
        MateriasComunesSyncService $materiasComunesSyncService
    ) {
        $this->universityService = $universityService;
        $this->parser = $parser; // Added
        $this->planClaseParser = $planClaseParser; // Added
        $this->cronogramaParser = $cronogramaParser; // Added
        $this->syncService = $syncService;
        $this->materiasComunesSyncService = $materiasComunesSyncService;
    }

    /**
     * Lista asignaturas con filtros cascading.
     * GET /api/asignaturas?sede_id=1&carrera_id=5&semestre=3
     */
    public function index(Request $request)
    {
        $query = Asignatura::query();

        // NOTE: Role-based filtering (Director de Carrera) is handled by the frontend
        // The frontend sends sede_id and carrera_id filters based on user's assigned data

        // Eager load grupos and context, optionally filtered by sede and carrera
        $sedeId = $request->input('sede_id');
        $carreraId = $request->input('carrera_id');

        $query->with(['grupos' => function ($q) use ($sedeId, $carreraId) {
            if ($sedeId) {
                $q->where('sede_id', $sedeId);
            }
            if ($carreraId) {
                // Durante la transición, mostramos los de la carrera O los que aún son NULL
                $q->where(function ($sub) use ($carreraId) {
                    $sub->where('carrera_id', $carreraId)
                        ->orWhereNull('carrera_id');
                });
            }
            $q->with('docente');
        }]);

        // Filtros (Pivote y Texto)
        if ($request->filled('sede_id') || $request->filled('carrera_id') || $request->filled('semestre')) {
            $query->whereHas('carreras', function ($q) use ($request) {
                if ($request->filled('sede_id')) {
                    $q->where(function ($sub) use ($request) {
                        $sub->where('asignatura_carrera.sede_id', $request->sede_id)
                            ->orWhere('carreras.sede_id', $request->sede_id);
                    });
                }
                if ($request->filled('carrera_id')) $q->where('carreras.id', $request->carrera_id);
                if ($request->filled('semestre')) $q->where('asignatura_carrera.semestre', $request->semestre);
            });

            // Cargar contexto específico para mostrar los datos correctos
            $query->with(['carreras' => function ($q) use ($request) {
                if ($request->filled('sede_id')) {
                    $q->where(function ($sub) use ($request) {
                        $sub->where('asignatura_carrera.sede_id', $request->sede_id)
                            ->orWhere('carreras.sede_id', $request->sede_id);
                    });
                }
                if ($request->filled('carrera_id')) $q->where('carreras.id', $request->carrera_id);
                if ($request->filled('semestre')) $q->where('asignatura_carrera.semestre', $request->semestre);
            }]);
        } else {
            $query->with('carreras');
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('nombre', 'like', "%{$term}%")
                    ->orWhere('codigo', 'like', "%{$term}%");
            });
        }

        $asignaturas = $query->limit(500)->get();
        $sedesMap = \App\Models\Sede::pluck('nombre', 'id'); // Cache sedes map

        return response()->json($asignaturas->map(function ($a) use ($sedesMap) {
            $context = $a->carreras->first(); // Contexto (filtrado o el primero)

            $docentes = $a->grupos->map(fn($g) => $g->docente)->filter()->unique('id');

            // Calcular progreso de documentación (basado en campos completados)
            $campos = [
                !empty($a->proposito_general),
                !empty($a->justificacion),
                !empty($a->metodologia_general),
                !empty($a->sistema_evaluacion),
                !empty($a->contenido_minimo),
                !empty($a->competencia_asignatura),
                $a->unidades()->count() > 0, // Tiene unidades
                $a->unidades()->whereHas('temas')->count() > 0, // Tiene temas
            ];
            $camposCompletados = count(array_filter($campos));
            $progreso = round(($camposCompletados / count($campos)) * 100);

            // Context resolution: Priority to the Group's Sede (Actual Assignment)
            // If the subject is here because of a group, show THAT group's location.

            // USER-SPECIFIC CONTEXT: If user is a teacher, prioritize THEIR group
            $user = auth()->user();
            $myGroup = null;

            if ($user && $user->docente) {
                // Find group for THIS teacher
                $myGroup = $a->grupos->where('docente_id', $user->docente->id)->first();
            }

            // Fallback: If no specific group for user (e.g. Admin or not assigned), use first group or context
            $firstGroup = $myGroup ?? $a->grupos->first();

            $actualSedeId = $firstGroup?->sede_id
                ?? $context?->pivot?->sede_id
                ?? $context?->sede_id
                ?? 1;

            $sedeNombre = 'Sede Desconocida';

            // Try to resolve name from DB or Map
            if ($firstGroup && $firstGroup->sede_id) {
                // Optimization: In real app, load 'grupos.sede' relationship.
                // For now, simple map or fetch. Assuming map is enough for standard IDs
                $sedeNombre = $sedesMap[$actualSedeId] ?? \App\Models\Sede::find($actualSedeId)?->nombre ?? 'Sede Desconocida';
            } else {
                $sedeNombre = $sedesMap[$actualSedeId] ?? 'N/A';
            }

            return [
                'id' => $a->id,
                'codigo' => $a->codigo,
                'nombre' => $a->nombre,
                'comun_token' => $a->comun_token, // Added for frontend indicator
                'creditos' => $a->creditos,
                'semestre' => $context?->pivot?->semestre,
                'horas_teoricas' => $a->horas_teoricas,
                'horas_practicas' => $a->horas_practicas,
                'carrera_id' => $firstGroup?->carrera_id ?? $context?->id, // ALSO fix Carrera: Use Group's career if possible
                'carrera_nombre' => $context?->nombre ?? 'N/A', // Keep context name as fallback, or fetch group's career name if relational
                'sede_id' => $actualSedeId,
                'sede_nombre' => $sedeNombre,
                'activa' => $a->deleted_at === null,
                'docentes' => $docentes->pluck('nombre_completo')->values(),
                'docente_nombre' => $docentes->isEmpty() ? null : $docentes->pluck('nombre_completo')->implode(', '), // Fix for card display
                'grupos_count' => $a->grupos->count(),
                'progreso_documentacion' => $progreso,
                'docentes_data' => $docentes->map(function ($d) use ($a) { // Para el diálogo de selección
                    // Calcular descripción de grupos para este docente
                    $gruposDocente = $a->grupos->where('docente_id', $d->id);
                    $desc = $gruposDocente->map(fn($g) => ($g->nombre ?? 'S/N') . ' (' . ($g->tipo ?? 'TEO') . ')')->implode(', ');

                    // IMPORTANTE: Tomar el carrera_id del primer grupo (aislado) o NULL
                    // Use optional chaining carefully. If db column missing, Model returns null usually.
                    $firstGroup = $gruposDocente->first();
                    $carreraId = $firstGroup->carrera_id ?? null;
                    $sedeId = $firstGroup->sede_id ?? null;

                    return [
                        'id' => $d->id,
                        'nombre' => $d->nombre_completo,
                        'descripcion_grupos' => $desc,
                        'carrera_id' => $carreraId,
                        'sede_id' => $sedeId
                    ];
                })->values()
            ];
        })); // END MAP
    }

    /**
     * Muestra el detalle completo fusionado.
     * Si no existe localmente, lo crea primero (First-Time-Use persistence).
     */
    public function show(Request $request, $id)
    {
        $targetUserId = auth()->id();

        // Si viene un docente_id (vista de director/admin)
        if ($request->has('docente_id')) {
            $docente = \App\Models\Docente::find($request->input('docente_id'));
            if ($docente && $docente->user_id) {
                $targetUserId = $docente->user_id;
            }
        }

        // 1. Busqueda local por ID con relaciones anidadas profundas para métricas
        $local = Asignatura::with([
            'unidades.temas' => function ($query) use ($targetUserId) {
                $query->with(['logros.indicadores', 'planificacionPersonal' => function ($q) use ($targetUserId) {
                    if ($targetUserId) {
                        $q->where('user_id', $targetUserId);
                    }
                }]);
            },
            'bibliografias', 'docentes', 'carreras', 'grupos.horarios.aula'
        ])->find($id);

        if ($local) {
            // Verificar si tiene unidades (Si no tiene, intentar sync)
            $careerCode = $request->input('career_code', 'SIS'); // Default fallback
            $branchCode = $request->input('branch_code', 'CBBA'); // Default fallback

            // Intentar obtener carrera y sede del request o del modelo
            // Esto es crucial para el Sync correcto si faltan datos
            $mainCarrera = $local->carreras->first(); // Asumimos una carrera principal por ahora

            if ($local->unidades()->count() === 0) {
                // ... (código existente de sync service) ...
                $actualBranchCode = $mainCarrera->sede->codigo ?? $branchCode; // Use ->sede->codigo safely
                $actualCareerCode = $mainCarrera->codigo ?? $careerCode;
                $this->syncService->syncAnalyticalProgram($local, $actualBranchCode, $actualCareerCode);
                $local->load([
                    'unidades.temas.logros.indicadores',
                    'unidades.temas.planificacionPersonal' => function ($q) use ($targetUserId) {
                        if ($targetUserId) {
                            $q->where('user_id', $targetUserId);
                        }
                    },
                    'bibliografias', 'docentes'
                ]);
            }

            // AUTO-SYNC (CONTENIDO DESCRIPTIVO)
            // Usamos el ID de la sede del contexto principal (Pivote > Carrera)
            $sedeId = $mainCarrera?->pivot?->sede_id ?? $mainCarrera?->sede_id ?? 0;
            if ($sedeId != 1 && (empty($local->descripcion) || empty($local->justificacion))) {
                $central = Asignatura::where('codigo', $local->codigo)
                    ->whereHas('carreras', function ($q) {
                        $q->where('asignatura_carrera.sede_id', 1);
                    })
                    ->first();

                if ($central && (!empty($central->descripcion) || !empty($central->justificacion))) {
                    // Copiar datos de Central a Local
                    $local->descripcion = $central->descripcion;
                    $local->justificacion = $central->justificacion;
                    $local->proposito_general = $central->proposito_general;
                    $local->metodologia_general = $central->metodologia_general;
                    $local->sistema_evaluacion = $central->sistema_evaluacion;
                    $local->contenido_minimo = $central->contenido_minimo;
                    $local->requisitos = $central->requisitos;
                    $local->save();
                }
            }

            $response = $local->toArray();

            // Mapear temas para incluir los campos aplastados (estrategias, evaluacion, secuencia_didactica)
            // de modo que el frontend pueda calcular el porcentaje idénticamente a getFullTema
            if (isset($response['unidades'])) {
                foreach ($response['unidades'] as &$u) {
                    if (isset($u['temas'])) {
                        foreach ($u['temas'] as &$t) {
                            $personal = $t['planificacion_personal'] ?? null;
                            
                            $t['estrategias'] = [
                                'metodologicas' => $personal['estrategias_metodologicas'] ?? $t['estrategias_metodologicas'] ?? '',
                                'aprendizaje' => $personal['estrategias_aprendizaje'] ?? $t['estrategias_aprendizaje'] ?? '',
                                'recursos' => $personal['estrategias_recursos'] ?? $t['estrategias_recursos'] ?? []
                            ];
                            $t['evaluacion'] = [
                                'formativa' => $personal['evaluacion_formativa'] ?? $t['evaluacion_formativa'] ?? ['actividades' => [], 'instrumentos' => [], 'evidencias' => []],
                                'sumativa' => $personal['evaluacion_sumativa'] ?? $t['evaluacion_sumativa'] ?? ['actividades' => [], 'instrumentos' => [], 'evidencias' => []]
                            ];
                            $t['secuencia_didactica'] = $personal['secuencia_didactica'] ?? $t['secuencia_didactica'] ?? [];
                            $t['logros_esperados'] = $t['logros'] ?? [];
                        }
                    }
                }
            }

            // Inyectar alias para que el formulario se llene solo
            $response['objetivo_general'] = $local->proposito_general;
            $response['saberes_previos'] = $local->requisitos;
            $response['metodologia_ensenanza'] = $local->metodologia_general;
            $response['criterios_evaluacion'] = $local->sistema_evaluacion;
            $response['contenido_minimo'] = $local->contenido_minimo;
            $response['justificacion'] = $local->justificacion;
            // Fix: Include semestre from pivot AND full relation objects
            $response['semestre'] = $mainCarrera?->pivot?->semestre;
            $response['carrera'] = $mainCarrera; // Pass full object (with sede loaded)
            $response['carreras'] = $local->carreras; // Pass all careers for potential multi-sede logic

            // USER-SPECIFIC CONTEXT (GLOBAL FIX):
            // Check if authenticated user is a teacher and has a specific group for this subject.
            // If so, prioritize THAT group's Sede/Context over the generic subject context.
            $currentUser = auth()->user();
            $mySpecifiedGroup = null;
            if ($currentUser && $currentUser->docente) {
                // Find first group assigned to this teacher
                $mySpecifiedGroup = $local->grupos->where('docente_id', $currentUser->docente->id)->first();
            }

            // Explicit sede_id injection. PRIORITY: User's Group > Pivot > Career > Fallback
            $resolvedSedeId = $mySpecifiedGroup?->sede_id
                ?? $mainCarrera?->pivot?->sede_id
                ?? $mainCarrera?->sede_id
                ?? 1;

            $response['sede_id'] = $resolvedSedeId;

            // Explicit sede_nombre
            $resolvedSedeNombre = 'Sede Desconocida';

            if ($mySpecifiedGroup && $mySpecifiedGroup->sede_id) {
                // Optimization: Try to find name in cached map or loaded relation
                $resolvedSedeNombre = \App\Models\Sede::find($resolvedSedeId)?->nombre ?? 'Sede Desconocida';
            } elseif ($mainCarrera?->sede && $mainCarrera->sede->id == $resolvedSedeId) {
                $resolvedSedeNombre = $mainCarrera->sede->nombre;
            } else {
                $sedeDb = \App\Models\Sede::find($resolvedSedeId);
                if ($sedeDb) {
                    $resolvedSedeNombre = $sedeDb->nombre;
                } else {
                    $mapa = [1 => 'Cochabamba', 2 => 'La Paz', 4 => 'Santa Cruz', 8 => 'El Alto', 10 => 'Cobija', 12 => 'Puerto Quijarro'];
                    $resolvedSedeNombre = $mapa[$resolvedSedeId] ?? 'Sede Desconocida (' . $resolvedSedeId . ')';
                }
            }
            $response['sede_nombre'] = $resolvedSedeNombre;


            // Horarios desde la estructura normalizada (grupos + horarios)
            $gruposQuery = $local->grupos();

            // FILTER: Si se proporciona carrera_id, intentar buscar correspondencia exacta
            $reqCarreraId = $request->input('carrera_id') ?: ($mainCarrera->id ?? null);

            
            // FILTER: Si se proporciona sede_id, filtrar por sede (CRITICAL FOR MULTI-SEDE)
            $reqSedeId = $request->input('sede_id') ?: $resolvedSedeId;
            
            if ($reqSedeId && $reqSedeId > 0) {
                 // FILTER: Si hay grupos aislados para esta sede, mostrar SOLO esos
                $gruposQuery->where('sede_id', $reqSedeId);
            }
            
            // SECURITY FILTERS (Directors & Teachers)
            if ($currentUser) {
                 // 1. DOCENTES: Ver solo sus grupos
                 if ($currentUser->rol_id === 6 && $currentUser->docente) {
                     $gruposQuery->where('docente_id', $currentUser->docente->id);
                 }
                 
                 // 2. DIRECTORES (Rol 3, 4, 5): Ver grupos de su Sede (Jurisdicción)
                 // IMPORTANTE: Relajar filtro de carrera para ver materias compartidas/servicio
                 if (in_array($currentUser->rol_id, [3, 4, 5])) {
                     $director = $currentUser->director;
                     if ($director && $director->sede_id) {
                         $gruposQuery->where('sede_id', $director->sede_id);
                         // NO FILTRAR POR CARRERA - Permitir ver grupos de servicio
                     }
                 }
            }

            $response['horarios_data'] = $gruposQuery
                ->with(['docente:id,nombre_completo', 'horarios.aula:id,nombre'])
                ->get()
                ->map(function ($grupo) {
                    return [
                        'id' => $grupo->id,
                        'asignatura_id' => $grupo->asignatura_id,
                        'carrera_id' => $grupo->carrera_id,
                        'sede_id' => $grupo->sede_id,
                        'grupo' => $grupo->nombre, // MAPEO CORRECTO: Usar nombre como grupo
                        'tipo' => $grupo->tipo,
                        'docente_nombre' => $grupo->docente?->nombre_completo,
                        'horarios' => $grupo->horarios->map(function ($h) {
                            return [
                                'id' => $h->id,
                                'id_horario_api' => $h->id_horario_api,
                                'dia' => $h->dia,
                                'hora_inicio' => $h->hora_inicio,
                                'hora_fin' => $h->hora_fin,
                                'aula' => $h->aula?->nombre
                            ];
                        })
                    ];
                });

            return response()->json($response);
        }

        // 4. Si NO existe localmente, retornamos 404 inmediato (Optimización: No Lazy Sync)
        return response()->json(['message' => 'Asignatura no encontrada o no sincronizada.'], 404);
    }

    /**
     * Sincroniza Unidades, Temas y Bibliografía desde la API al modelo Local.
     */
    // Método syncAnalyticalProgram eliminado y movido a AsignaturaSyncService

    /**
     * Actualizar campos extendidos (Justificación, Metodología, etc).
     */
    public function update(Request $request, $id)
    {
        $local = Asignatura::findOrFail($id);

        // Frontend -> DB Mapping
        $data = $request->only([
            'sigla',
            'descripcion',
            'creditos',
            'semestre',
            'nombre',
            'horas_teoricas',
            'horas_practicas',
            'horas_laboratorio',
            'contenidos_minimos',
            'contenido_minimo',
            'activa',
            // Nuevos Campos
            'area_desempenio',
            'tipo_curso',
            'modalidad',
            'carga_horaria_total',
            'horas_detalle',
            'sesiones_semanales',
            'horas_teoricas',
            'sesiones_semanales_teoricas',
            'sesiones_semanales_practicas',
            'docente_formacion',
            'docente_telefono',
            'docente_email'
        ]);

        // Mapeo manual
        if ($request->has('objetivo_general')) $local->proposito_general = $request->objetivo_general;
        if ($request->has('metodologia_ensenanza')) $local->metodologia_general = $request->metodologia_ensenanza;
        if ($request->has('criterios_evaluacion')) $local->sistema_evaluacion = $request->criterios_evaluacion;
        if ($request->has('contenido_minimo')) $local->contenido_minimo = $request->contenido_minimo;

        // FIX MAPEO REQUISITOS: Priorizar 'requisitos' (nuevo input) sobre 'saberes_previos' (legacy)
        if ($request->has('requisitos')) {
            $local->requisitos = $request->requisitos;
        } elseif ($request->has('saberes_previos')) {
            $local->requisitos = $request->saberes_previos;
        }

        // FIX: Justificación no se estaba mapeando porque no está en $request->only() ni aquí
        if ($request->has('justificacion')) $local->justificacion = $request->justificacion;

        // FIX: Nuevos campos de Programa de Asignatura que faltaban en el update manual
        if ($request->has('competencia_global')) $local->competencia_global_especifica = $request->competencia_global; // Frontend sends 'competencia_global'
        if ($request->has('competencia_global_especifica')) $local->competencia_global_especifica = $request->competencia_global_especifica; // Handle both keys

        if ($request->has('competencia_asignatura')) $local->competencia_asignatura = $request->competencia_asignatura;
        if ($request->has('elementos_competencia')) $local->elementos_competencia = $request->elementos_competencia;

        // Handle array or string for reglamento
        if ($request->has('reglamento_normativa')) {
            $local->reglamento_normativa = $request->reglamento_normativa;
        }

        if ($request->has('organizacion_calendario')) $local->organizacion_calendario = $request->organizacion_calendario;

        $local->fill($data); // Fill the rest
        $local->save();

        $local->save();

        // Handle Carrera change if provided
        if ($request->has('carrera_id') && $request->carrera_id) {
            $local->carrera_id = $request->carrera_id;
            $local->save();
        }

        // Construir respuesta con alias para el frontend (igual que en show)
        $response = $local->toArray();
        $response['objetivo_general'] = $local->proposito_general;
        $response['saberes_previos'] = $local->requisitos;
        $response['metodologia_ensenanza'] = $local->metodologia_general;
        $response['criterios_evaluacion'] = $local->sistema_evaluacion;
        $response['contenido_minimo'] = $local->contenido_minimo;
        $response['justificacion'] = $local->justificacion;

        // Necesario reload para relaciones si se ocupara, pero aqui es update simple
        // Necesario reload para relaciones si se ocupara
        // Si el frontend necesita carrera/sede, habría que cargarlas:
        // load carreras instead of carrera
        $local->load(['carreras.sede']);
        $mainCarrera = $local->carreras->first();
        $response['carrera'] = $mainCarrera; // Para compatibilidad frontend si usa .carrera
        $response['semestre'] = $mainCarrera?->pivot?->semestre; // Fix: Include semestre

        // PROPAGACION DE DATOS: Si es Cochabamba (ID 1), actualizar "espejos" en otras sedes
        if ($mainCarrera && $mainCarrera->sede_id == 1) { // 1 = Cochabamba (Central)
            Asignatura::where('codigo', $local->codigo)
                ->where('id', '!=', $local->id)
                ->update([
                    'descripcion' => $local->descripcion,
                    'justificacion' => $local->justificacion,
                    'proposito_general' => $local->proposito_general, // Db column
                    'metodologia_general' => $local->metodologia_general, // Db column
                    'sistema_evaluacion' => $local->sistema_evaluacion, // Db column
                    'contenido_minimo' => $local->contenido_minimo,
                    'requisitos' => $local->requisitos,
                    'updated_at' => now()
                ]);
        }

        // SINCRONIZACIÓN MATERIAS COMUNES: Propagar a materias vinculadas del mismo docente
        $synced = $this->materiasComunesSyncService->syncAllDocumentationToLinked($local);
        $response['synced_to_comunes'] = $synced;

        return response()->json($response);
    }

    public function cambiarEstado(Request $request, $id)
    {
        $request->validate([
            'estado' => 'required|in:EN_PROCESO,APROBADO'
        ]);

        $asignatura = Asignatura::findOrFail($id);
        $asignatura->update(['estado' => $request->estado]);

        return response()->json($asignatura);
    }

    public function store(Request $request)
    {
        $request->validate([
            'codigo' => 'required|unique:asignaturas,codigo',
            'nombre' => 'required',
            'carrera_id' => 'required|exists:carreras,id',
            'semestre' => 'required|integer'
        ]);

        $carrera = Carrera::findOrFail($request->carrera_id);

        $asignatura = Asignatura::create($request->except(['carrera_id', 'semestre', 'sede_id']));

        // Attach to pivot with context
        $asignatura->carreras()->attach($carrera->id, [
            'semestre' => $request->semestre,
            'sede_id' => $carrera->sede_id ?? 1 // Default to 1 if null, or infer
        ]);

        return response()->json($asignatura, 201);
    }

    public function destroy($id)
    {
        $asignatura = Asignatura::findOrFail($id);
        $asignatura->delete();
        return response()->json(['message' => 'Asignatura eliminada correctamente']);
    }

    public function assignDocentes(Request $request, $id)
    {
        // DEPRECATED: Docentes are now assigned via Grupos using 'hasManyThrough'
        return response()->json([
            'error' => 'La asignación directa de docentes ha sido deprecada. Por favor, asigne el docente a un Grupo específico.',
            'action_required' => 'Use el endpoint de creación/edición de Grupos.'
        ], 400);
    }

    /**
     * Importar datos desde Word (Solo Sede Cochabamba)
     */
    public function importWord(Request $request, $id, \App\Services\DocumentParserService $parser)
    {
        $asignatura = Asignatura::findOrFail($id);

        // SOBERANÍA DE SEDE: Validar basado en el usuario autenticado, NO en los metadatos de la materia
        // Esto corrige el bug donde materias con metadatos erróneos (ej: ADM-321) bloqueaban a docentes legítimos
        $userSedeId = auth()->user()->sede_id ?? 1;

        // Solo permitir importación a usuarios de Sede Central (Cochabamba = ID 1)
        if ($userSedeId != 1) {
            return response()->json([
                'error' => 'La importación solo está permitida para la Sede Central (Cochabamba).'
            ], 403);
        }

        // LOG para debugging
        \Illuminate\Support\Facades\Log::debug('ImportWord: Usuario sede_id=' . $userSedeId . ', Asignatura=' . $asignatura->codigo);

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No se ha subido ningún archivo.'], 400);
        }

        try {
            $data = $parser->parseWord($request->file('file'));

            // Flags de importación (Refined split: Word only for Units/Themes)
            $importDatos = false;
            $importUnidades = true;
            $importBiblio = false;

            // 1. IMPORTAR DATOS GENERALES (Plan de Asignatura)
            if ($importDatos) {
                if ($data['justificacion']) $asignatura->justificacion = $data['justificacion'];
                if ($data['proposito_general']) $asignatura->proposito_general = $data['proposito_general'];
                if ($data['metodologia_general']) $asignatura->metodologia_general = $data['metodologia_general'];
                if ($data['sistema_evaluacion']) $asignatura->sistema_evaluacion = $data['sistema_evaluacion'];
                if ($data['contenido_minimo']) $asignatura->contenido_minimo = $data['contenido_minimo'];
                if ($data['requisitos']) $asignatura->requisitos = $data['requisitos'];

                if (!empty($data['competencia_asignatura'])) {
                    $asignatura->competencia_asignatura = $data['competencia_asignatura'];
                }
                if ($data['elementos_competencia']) $asignatura->elementos_competencia = $data['elementos_competencia'];

                if (!empty($data['competencia_global_especifica'])) $asignatura->competencia_global_especifica = $data['competencia_global_especifica'];
                if (!empty($data['reglamento_normativa'])) $asignatura->reglamento_normativa = $data['reglamento_normativa'];
                if (!empty($data['organizacion_calendario'])) $asignatura->organizacion_calendario = $data['organizacion_calendario'];

                // Nuevos Campos Generales (Autocompletado)
                if (!empty($data['creditos']) && $data['creditos'] > 0) $asignatura->creditos = $data['creditos'];
                if (!empty($data['carga_horaria_total']) && $data['carga_horaria_total'] > 0) $asignatura->carga_horaria_total = $data['carga_horaria_total'];
                if (!empty($data['horas_teoricas']) && $data['horas_teoricas'] > 0) $asignatura->horas_teoricas = $data['horas_teoricas'];
                if (!empty($data['horas_practicas']) && $data['horas_practicas'] > 0) $asignatura->horas_practicas = $data['horas_practicas'];

                if (!empty($data['modalidad'])) $asignatura->modalidad = $data['modalidad'];
                if (!empty($data['tipo_curso'])) $asignatura->tipo_curso = $data['tipo_curso'];
                if (!empty($data['area_desempenio'])) $asignatura->area_desempenio = $data['area_desempenio'];
                if (!empty($data['requisitos'])) $asignatura->requisitos = $data['requisitos'];

                $asignatura->save();
            }

            // 2. IMPORTAR BIBLIOGRAFIA
            if ($importBiblio) {
                Log::info("Procesando Bibliografía...");
                // MODO SOBRESCRITURA: Borramos la bibliografía anterior para evitar duplicados o basura
                // (Opcional: Solo borrar si hay nueva data?)
                if (!empty($data['bibliografia_basica']) || !empty($data['bibliografia_complementaria'])) {
                    $asignatura->bibliografias()->delete();
                    Log::info("Bibliografía anterior eliminada.");
                }

                $this->saveBibliografias($asignatura, $data['bibliografia_basica'], 'BASICA');
                $this->saveBibliografias($asignatura, $data['bibliografia_complementaria'], 'COMPLEMENTARIA');
            }

            // 3. IMPORTAR UNIDADES Y TEMAS
            $importedUnits = false;


            // PRIORIDAD: Estructura Completa (Unidades + Temas)
            if ($importUnidades && !empty($data['estructura_unidades'])) {
                foreach ($data['estructura_unidades'] as $uNum => $uData) {
                    // Crear Unidad
                    $tituloUnidad = $uData['titulo'] ?: "UNIDAD $uNum";
                    // Prevent Data Too Long for unit title
                    if (strlen($tituloUnidad) > 250) {
                        $tituloUnidad = substr($tituloUnidad, 0, 247) . '...';
                    }

                    $unidad = $asignatura->unidades()->updateOrCreate(
                        ['numero' => $uNum],
                        [
                            'titulo' => $tituloUnidad,
                            'horas' => 0
                            // 'elemento_competencia' => ... ? No viene explícito en este formato,
                            // tal vez podríamos usar el contenido_raw como descripción general o competencia
                            // $unidad->elemento_competencia = substr($uData['contenido_raw'], 0, 500);
                        ]
                    );

                    // Crear Temas
                    if (!empty($uData['temas'])) {
                        // Borrar temas anteriores de esta unidad para evitar duplicados en re-import ??
                        // Mejor: updateOrCreate basado en numero/orden?
                        // El parser nos da un orden secuencial en 'temas'.

                        // Opcion segura: Borrar y recrear
                        $unidad->temas()->delete();

                        foreach ($uData['temas'] as $i => $temaData) {
                            $rawTitle = $temaData['titulo'];
                            $rawContent = $temaData['contenido'] ?? '';

                            // Logica de Truncado seguro
                            if (strlen($rawTitle) > 190) {
                                $tituloFinal = substr($rawTitle, 0, 187) . '...';
                                // Si el título era gigante, probablemente contenía parte del contenido.
                                // Lo concatenamos al inicio del contenido para no perderlo.
                                $contenidoFinal = $rawTitle . "\n" . $rawContent;
                            } else {
                                $tituloFinal = $rawTitle;
                                $contenidoFinal = $rawContent;
                            }

                            $unidad->temas()->create([
                                'titulo' => $tituloFinal,
                                'contenido' => $contenidoFinal,
                                'contenido_items' => $temaData['contenido_items'] ?? [], // Guardar items parseados
                                'orden' => $i + 1
                            ]);
                        }
                    }
                    $importedUnits = true;
                }
            }

            return response()->json([
                'message' => 'Importación completada correctamente.',
                'imported_units' => $importedUnits
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error importando Word: ' . $e->getMessage());
            return response()->json(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Importar Plan de Clase desde Word (Estructura Tabular "PLAN DE CLASE")
     */
    public function importPlanClase(Request $request, $id)
    {
        $asignatura = Asignatura::findOrFail($id);

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No se ha subido ningún archivo.'], 400);
        }

        try {
            // 1. Parsear el archivo usando el parser especializado
            // 1. Parsear el archivo usando el parser especializado
            $parsedData = $this->planClaseParser->parse($request->file('file'));

            // 2. Poblar los datos en la Asignatura
            // Mapeo de campos parseados -> Modelo Asignatura

            if (!empty($parsedData['competencias']['competencia_asignatura'])) {
                $asignatura->competencia_asignatura = $parsedData['competencias']['competencia_asignatura'];
            }
            // Concatenar elementos si hay varios, o guardar como texto
            if (!empty($parsedData['competencias']['elementos_competencia'])) {
                $asignatura->elementos_competencia = implode("\n", $parsedData['competencias']['elementos_competencia']);
            }

            // Contenidos (si el parser extrajo contenidos mínimos globales)
            // ...

            $asignatura->save();

            // 3. Estructura de Unidades (Si el Plan de Clase contiene el desglose)
            if (!empty($parsedData['unidades'])) {
                foreach ($parsedData['unidades'] as $uNum => $uData) {
                    $titulo = $uData['titulo'] ?? "UNIDAD $uNum";

                    $unidad = $asignatura->unidades()->updateOrCreate(
                        ['numero' => $uNum],
                        ['titulo' => substr($titulo, 0, 250)]
                    );

                    // Temas dentro de la unidad
                    if (!empty($uData['temas'])) {
                        // Opcional: Eliminar temas anteriores de esta unidad para evitar duplicados/basura
                        // $unidad->temas()->delete(); 

                        foreach ($uData['temas'] as $tNum => $temaData) {
                            $unidad->temas()->updateOrCreate(
                                ['orden' => $tNum], // Usamos 'orden' como identificador único dentro de la unidad
                                [
                                    'titulo' => substr($temaData['titulo'], 0, 190),
                                    'contenido_conceptual' => $temaData['contenido_conceptual'] ?? [],
                                    'contenido_procedimental' => $temaData['contenido_procedimental'] ?? [],
                                    'contenido_actitudinal' => $temaData['contenido_actitudinal'] ?? [],
                                    'estrategias_metodologicas' => $temaData['estrategias_metodologicas'] ?? null,
                                    'estrategias_aprendizaje' => $temaData['estrategias_aprendizaje'] ?? null,
                                    'estrategias_recursos' => $temaData['estrategias_recursos'] ?? [],
                                    'evaluacion_formativa' => $temaData['evaluacion_formativa'] ?? [],
                                    'evaluacion_sumativa' => $temaData['evaluacion_sumativa'] ?? [],
                                    'secuencia_didactica' => $temaData['secuencia_didactica'] ?? [], /* SI EXISTE COLUMNA EN TABLA */
                                    'resultado_aprendizaje' => $temaData['logros'] ?? null,
                                ]
                            );
                        }
                    }
                }
            }

            return response()->json([
                'message' => 'Plan de Clase importado correctamente.',
                'data_preview' => $parsedData
            ]);

        } catch (\Exception $e) {
            Log::error('Error importando Plan de Clase: ' . $e->getMessage());
            return response()->json(['error' => 'Error al procesar Plan de Clase: ' . $e->getMessage()], 500);
        }
    }

    private function saveBibliografias(Asignatura $asignatura, array $items, $tipo)
    {
        if (empty($items)) return;

        foreach ($items as $item) {
            try {
                $item = trim($item);
                if (empty($item)) continue;

                // Truncado estricto a 180 caracteres
                $titulo = substr($item, 0, 180);
                $descripcion = (strlen($item) > 180) ? $item : null;

                // Verificar duplicados simples
                $exists = $asignatura->bibliografias()
                    ->where('titulo', $titulo)
                    ->where('tipo', $tipo)
                    ->exists();

                if (!$exists) {
                    $asignatura->bibliografias()->create([
                        'titulo' => $titulo,
                        'descripcion' => $descripcion,
                        'tipo' => $tipo,
                        'autor' => 'AA.VV.',
                        'anio' => 'S/F',
                        'editorial' => 'S/E'
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Error guardando bibliografia '$item': " . $e->getMessage());
            }
        }
    }

    /**
     * Importar Cronograma Parcial (PAC) desde Excel (6 Semanas)
     */
    public function importCronograma(Request $request, $id)
    {
        $asignatura = Asignatura::findOrFail($id);
        $grupoId = $request->input('grupo_id');

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No se ha subido ningún archivo.'], 400);
        }

        try {
            DB::beginTransaction();

            // 1. Parsear el archivo
            // Retorna ['sesiones' => [...], 'metadata' => [...]]
            $parsedData = $this->cronogramaParser->parseCronograma($request->file('file'));
            $sesionesParsed = $parsedData['sesiones'];

            // 2. Obtener cronogramas existentes (SOLO MASTER PLAN)
            // La importación siempre debe actualizar el plan maestro.
            $query = $asignatura->cronogramas()
                ->whereNull('grupo_id')
                ->orderBy('numero_sesion');
            
            // if ($grupoId) { ... } // IGNORAR GRUPO, SIEMPRE MASTER
            
            $existingCronogramas = $query->get();

            if ($existingCronogramas->isEmpty()) {
                throw new \Exception("No hay planificación generada para este grupo. Genere la planificación primero.");
            }

            // 3. Mapeo y Actualización (Matching por Semana + Secuencia)
            // Agrupar ambos por semana
            $existingByWeek = $existingCronogramas->groupBy('semana_academica');
            $parsedByWeek = collect($sesionesParsed)->groupBy('semana');

            $updatedCount = 0;

            foreach ($parsedByWeek as $semana => $parsedItems) {
                if (isset($existingByWeek[$semana])) {
                    $existingItems = $existingByWeek[$semana]->values(); // Reset keys to 0,1,2...
                    
                    Log::info("Semana $semana matching: Parsed=" . count($parsedItems) . " Existing=" . $existingItems->count());

                    // Iterar secuencialmente
                    foreach ($parsedItems as $index => $pItem) {
                        if (isset($existingItems[$index])) {
                            $cronograma = $existingItems[$index];
                            
                            // Actualizar campos
                            $cronograma->update([
                                // 'contenido_conceptual' => $pItem['contenido_conceptual'] ?: $pItem['contenido'], // Fallback content
                                'contenido_conceptual' => $pItem['contenido_conceptual'],
                                'contenido_procedimental' => $pItem['contenido_procedimental'],
                                'contenido_actitudinal' => $pItem['contenido_actitudinal'],
                                'criterios_desempeno' => $pItem['criterios_desempeno'],
                                'instrumentos_evaluacion' => $pItem['instrumentos_evaluacion'],
                                // Si queremos sobrescribir fecha del Excel:
                                // 'fecha' => $pItem['fecha'] ?: $cronograma->fecha
                            ]);
                            $updatedCount++;
                        }
                    }
                } else {
                    Log::warning("Semana $semana not found in existing cronogramas.");
                }
            }

            DB::commit();

            return response()->json([
                'message' => "Importación completada. Se actualizaron $updatedCount sesiones.",
                'debug_parsed' => count($sesionesParsed),
                'debug_existing' => $existingCronogramas->count()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error importando Cronograma PAC: ' . $e->getMessage());
            return response()->json(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
        }
    }


    /**
     * Endpoint público para obtener todas las materias con sus programas analíticos completos.
     * GET /api/programas-analiticos?sede_id=1&carrera_id=5&semestre=3
     */
    public function programasAnaliticos(Request $request)
    {
        // Validar token estático (sin Sanctum)
        $expectedToken = env('PROGRAMAS_API_TOKEN', 'unitepc-programas-2026');
        $providedToken = $request->bearerToken() ?? $request->query('token');

        if (!$providedToken || $providedToken !== $expectedToken) {
            return response()->json(['error' => 'Token inválido o no proporcionado.'], 401);
        }

        $query = Asignatura::query();

        // Eager load: estructura completa del programa analítico
        $query->with([
            'unidades.temas.logros.indicadores',
            'unidades.temas.secuencias',
            'unidades.temas.bibliografias',
            'bibliografias',
            'carreras.sede',
        ]);

        // Filtros opcionales
        if ($request->filled('sede_id') || $request->filled('carrera_id') || $request->filled('semestre')) {
            $query->whereHas('carreras', function ($q) use ($request) {
                if ($request->filled('sede_id')) {
                    $q->where(function ($sub) use ($request) {
                        $sub->where('asignatura_carrera.sede_id', $request->sede_id)
                            ->orWhere('carreras.sede_id', $request->sede_id);
                    });
                }
                if ($request->filled('carrera_id')) $q->where('carreras.id', $request->carrera_id);
                if ($request->filled('semestre')) $q->where('asignatura_carrera.semestre', $request->semestre);
            });
        }

        // Búsqueda por nombre o código
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('nombre', 'like', "%{$term}%")
                    ->orWhere('codigo', 'like', "%{$term}%");
            });
        }

        $asignaturas = $query->orderBy('nombre')->get();

        return response()->json([
            'total' => $asignaturas->count(),
            'data' => $asignaturas->map(function ($a) {
                $mainCarrera = $a->carreras->first();

                return [
                    'id' => $a->id,
                    'codigo' => $a->codigo,
                    'nombre' => $a->nombre,
                    'creditos' => $a->creditos,
                    'semestre' => $mainCarrera?->pivot?->semestre,
                    'carrera' => $mainCarrera ? [
                        'id' => $mainCarrera->id,
                        'nombre' => $mainCarrera->nombre,
                        'sede' => $mainCarrera->sede?->nombre,
                    ] : null,

                    // Datos generales del programa
                    'descripcion' => $a->descripcion,
                    'justificacion' => $a->justificacion,
                    'proposito_general' => $a->proposito_general,
                    'competencia_asignatura' => $a->competencia_asignatura,
                    'competencia_global_especifica' => $a->competencia_global_especifica,
                    'elementos_competencia' => $a->elementos_competencia,
                    'contenido_minimo' => $a->contenido_minimo,
                    'metodologia_general' => $a->metodologia_general,
                    'sistema_evaluacion' => $a->sistema_evaluacion,
                    'requisitos' => $a->requisitos,

                    // Estructura del programa analítico
                    'unidades' => $a->unidades->map(function ($u) {
                        return [
                            'id' => $u->id,
                            'numero' => $u->numero,
                            'titulo' => $u->titulo,
                            'elemento_competencia' => $u->elemento_competencia,
                            'temas' => $u->temas->map(function ($t) {
                                return [
                                    'id' => $t->id,
                                    'titulo' => $t->titulo,
                                    'orden' => $t->orden,
                                    'resultado_aprendizaje' => $t->resultado_aprendizaje,
                                    'contenido_items' => $t->contenido_items,
                                    'contenido_conceptual' => $t->contenido_conceptual,
                                    'contenido_procedimental' => $t->contenido_procedimental,
                                    'contenido_actitudinal' => $t->contenido_actitudinal,
                                    'estrategias_metodologicas' => $t->estrategias_metodologicas,
                                    'estrategias_aprendizaje' => $t->estrategias_aprendizaje,
                                    'estrategias_recursos' => $t->estrategias_recursos,
                                    'evaluacion_formativa' => $t->evaluacion_formativa,
                                    'evaluacion_sumativa' => $t->evaluacion_sumativa,
                                    'horas_teoricas' => $t->horas_teoricas,
                                    'horas_practicas' => $t->horas_practicas,

                                    'logros_esperados' => $t->logros->map(function ($l) {
                                        return [
                                            'id' => $l->id,
                                            'descripcion' => $l->descripcion,
                                            'tipo_logro' => $l->tipo_logro,
                                            'indicadores' => $l->indicadores->map(fn($i) => [
                                                'id' => $i->id,
                                                'descripcion' => $i->descripcion,
                                            ]),
                                        ];
                                    }),
                                    'bibliografias' => $t->bibliografias->map(fn($b) => [
                                        'id' => $b->id,
                                        'titulo' => $b->titulo,
                                        'autor' => $b->autor,
                                    ]),
                                ];
                            }),
                        ];
                    }),

                    // Bibliografía general de la asignatura
                    'bibliografias' => $a->bibliografias->map(fn($b) => [
                        'id' => $b->id,
                        'titulo' => $b->titulo,
                        'autor' => $b->autor,
                        'editorial' => $b->editorial,
                        'anio' => $b->anio,
                        'tipo' => $b->tipo,
                    ]),

                    // Progreso
                    'progreso' => $a->estadisticas_progreso,
                ];
            }),
        ]);
    }





}
