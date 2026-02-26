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
            }, 'unidades.temas.planificacionPersonal', 'unidades.temas.logros.bancoPreguntas', 'cronogramas', 'bibliografias']);
        } else {
            $query->with(['carreras', 'unidades.temas.planificacionPersonal', 'unidades.temas.logros.bancoPreguntas', 'cronogramas', 'bibliografias']);
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

            // Calcular progreso de documentación usando el accesor centralizado del modelo (que incluye planes de clase)
            $progreso = $a->progreso;

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
                'indicadores_documentacion' => $a->indicadores_documentacion,
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
            
            // Sync bibliographies to sister subjects
            app(\App\Services\MateriasComunesSyncService::class)->syncBibliografias($asignatura);

            return response()->json([
                'message' => 'Importación completada correctamente.',
                'imported_units' => $importedUnits
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error importando Word: ' . $e->getMessage());
            return response()->json(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
        }
    }

    public function importPlanClase(Request $request, $id, \App\Services\PlanClaseParserService $parser)
    {
        $asignatura = Asignatura::findOrFail($id);

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No se ha subido ningún archivo.'], 400);
        }

        try {
            $data = $parser->parse($request->file('file'));
            $stats = ['updated' => 0, 'skipped' => 0];

            if (!empty($data['unidades'])) {
                // --- GLOBAL SEQUENTIAL MAPPING STRATEGY ---
                // Problem: Excel might group all themes under "Unidad 1", while DB splits them
                // into separate Units (T1->U1, T2->U2...). Or DB has 'orden=1' everywhere.
                // Solution: Flatten both lists and map by index (1st Excel Theme = 1st DB Theme).

                // 1. Flatten Parsed Themes
                $allParsedTemas = [];
                foreach ($data['unidades'] as $uData) {
                    if (!empty($uData['temas'])) {
                        foreach ($uData['temas'] as $tema) {
                            $allParsedTemas[] = $tema;
                        }
                    }
                }

                // Sort parsed by 'orden' just in case parser was jumbled (it shouldn't be)
                usort($allParsedTemas, fn($a, $b) => $a['orden'] <=> $b['orden']);

                // 2. Fetch ALL DB Themes for this Subject (Ordered by Creation/ID)
                // Assuming "Programa Analitico" created them in order.
                $allDbTemas = \App\Models\Tema::whereIn('unidad_id', $asignatura->unidades->pluck('id'))
                    ->orderBy('id')
                    ->get();

                Log::info("Import: Parsed " . count($allParsedTemas) . " themes. Found " . $allDbTemas->count() . " themes in DB.");

                // 3. Map and Update
                foreach ($allParsedTemas as $index => $temaData) {
                    $foundTema = $allDbTemas->get($index);

                    if ($foundTema) {
                        // AUTO-FIX: Ensure 'orden' matches sequence (1-based)
                        if ($foundTema->orden != ($index + 1)) {
                            $foundTema->update(['orden' => $index + 1]);
                        }

                        $updateData = [
                            'resultado_aprendizaje' => $temaData['logros'] ?? $foundTema->resultado_aprendizaje,
                        ];

                        // Merge contenidos
                        if (!empty($temaData['contenidos']['conceptual'])) $updateData['contenido_conceptual'] = $temaData['contenidos']['conceptual'];
                        if (!empty($temaData['contenidos']['procedimental'])) $updateData['contenido_procedimental'] = $temaData['contenidos']['procedimental'];
                        if (!empty($temaData['contenidos']['actitudinal'])) $updateData['contenido_actitudinal'] = $temaData['contenidos']['actitudinal'];

                        // Append general content stuff to items
                        if (!empty($temaData['contenido_items'])) {
                            $currentItems = $foundTema->contenido_items ?? [];
                            // Evitar duplicados simples
                            $newItems = array_diff($temaData['contenido_items'], $currentItems);
                            $updateData['contenido_items'] = array_merge($currentItems, $newItems);
                        }

                        $foundTema->update($updateData);

                        // Save Logros Esperados and Indicadores (Multiple per Theme)
                        // User Logic: Each line in Logros corresponds to line in Indicadores (by index)
                        if (!empty($temaData['logros_esperados_list'])) {
                            // WIPE OLD DATA TO PREVENT DUPLICATES
                            foreach ($foundTema->logros as $oldLogro) {
                                $oldLogro->indicadores()->delete();
                                $oldLogro->delete();
                            }
                            // Refresh relationship
                            $foundTema->load('logros');

                            foreach ($temaData['logros_esperados_list'] as $idx => $logroDesc) {
                                // Create Logro (Fresh)
                                $logro = \App\Models\LogroEsperado::create([
                                    'tema_id' => $foundTema->id,
                                    'descripcion' => $logroDesc,
                                    'periodo' => '1',
                                    'tipo_logro' => 'SABER HACER'
                                ]);

                                // Find corresponding Indicador
                                $indDesc = $temaData['indicadores_list'][$idx] ?? null;

                                if (!empty($indDesc) && $logro) {
                                    \App\Models\Indicador::create([
                                        'logro_esperado_id' => $logro->id,
                                        'descripcion' => $indDesc
                                    ]);
                                }
                            }
                        }

                        // SAVE NEW FIELDS (Contenidos, Estrategias, Evaluacion, Secuencia)

                        // 1. Update Tema Contenidos
                        $temaUpdate = [];
                        if (!empty($temaData['contenido_conceptual'])) $temaUpdate['contenido_conceptual'] = $temaData['contenido_conceptual'];
                        if (!empty($temaData['contenido_actitudinal'])) $temaUpdate['contenido_actitudinal'] = $temaData['contenido_actitudinal'];
                        // Procedimental is empty array as requested
                        if (array_key_exists('contenido_procedimental', $temaData)) $temaUpdate['contenido_procedimental'] = $temaData['contenido_procedimental'];

                        // Apply updates if any
                        if (!empty($temaUpdate)) {
                            $foundTema->update($temaUpdate);
                        }

                        // 2. Save Planificacion Personal (Strategy, Eval, Seq)
                        // Ensure we have logged in user or default owner
                        $userId = \Illuminate\Support\Facades\Auth::id() ?? 1; // Fallback to 1 if CLI

                        \App\Models\PlanificacionPersonal::updateOrCreate(
                            [
                                'tema_id' => $foundTema->id,
                                'user_id' => $userId
                            ],
                            [
                                'estrategias_metodologicas' => $temaData['estrategias_metodologicas'] ?? '',
                                'estrategias_aprendizaje' => $temaData['estrategias_aprendizaje'] ?? '',
                                'estrategias_recursos' => $temaData['estrategias_recursos'] ?? [],

                                'evaluacion_formativa' => $temaData['evaluacion_formativa'] ?? [],
                                'evaluacion_sumativa' => $temaData['evaluacion_sumativa'] ?? [],

                                'secuencia_didactica' => $temaData['secuencia_didactica'] ?? []
                            ]
                        );
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                        \Illuminate\Support\Facades\Log::warning("Import: No matching DB theme for Excel Theme #{$temaData['orden']} (Index $index)");
                    }
                }
            } else {
                return response()->json(['error' => 'No se detectaron unidades o temas en el archivo.'], 422);
            }

            return response()->json(['message' => 'Plan de Clase procesado. Se actualizaron ' . $stats['updated'] . ' temas.', 'data' => $data, 'stats' => $stats]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Import Plan Clase Error: " . $e->getMessage());
            return response()->json(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
        }
    }




    /**
     * Importar Plan de Clase desde Word (Estructura Tabular "PLAN DE CLASE")
     */
    public function importExcel(Request $request, $id)
    {
        $asignatura = Asignatura::findOrFail($id);

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No se ha subido ningún archivo.'], 400);
        }

        try {
            $file = $request->file('file');
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getPathname());

            // 1. Intentar seleccionar la hoja 'PAC' si existe, si no la activa
            $sheet = $spreadsheet->getSheetByName('PAC') ?: $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, false, false, false);

            // LOG DEPURACIÓN: Ver qué datos hay realmente en el archivo subido
            \Illuminate\Support\Facades\Log::info("--- EXCEL CONTENT DUMP (First 100 rows) ---");
            foreach (array_slice($rows, 0, 100) as $rIdx => $row) {
                foreach ($row as $cIdx => $cell) {
                    $v = trim($cell ?? '');
                    if ($v !== '') {
                        $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cIdx + 1) . ($rIdx + 1);
                        \Illuminate\Support\Facades\Log::info("EXTRACTED [$coord]: $v");
                    }
                }
            }
            \Illuminate\Support\Facades\Log::info("--- END DUMP ---");
            // FUNCIÓN DE BÚSQUEDA GLOBAL: Busca una etiqueta en TODO el grid
            // y devuelve el primer valor no vacío que NO sea la etiqueta misma NI un título de sección.
            $searchGrid = function ($label, $limitCols = 15, $limitRows = 10, $strictHeaderSkip = true) use ($rows) {
                $labelLower = mb_strtolower(trim($label));
                foreach ($rows as $rIdx => $row) {
                    if (empty($row)) continue;
                    foreach ($row as $cIdx => $cell) {
                        $cellVal = mb_strtolower(trim($cell ?? ''));
                        if ($cellVal !== '' && str_contains($cellVal, $labelLower)) {
                            // Una vez encontrada la etiqueta, buscamos el primer contenido útil en un radio grande
                            for ($dr = 0; $dr < $limitRows; $dr++) {
                                for ($dc = 0; $dc < $limitCols; $dc++) {
                                    $checkRow = $rIdx + $dr;
                                    $checkCol = $cIdx + $dc;
                                    if (!isset($rows[$checkRow][$checkCol])) continue;

                                    $v = trim($rows[$checkRow][$checkCol]);
                                    if ($v === '') continue;

                                    // REGLAS PARA DESCARTAR:
                                    // 1. No es la etiqueta misma ni contiene la etiqueta si es muy corto (título)
                                    $vLower = mb_strtolower($v);
                                    if ($vLower === $labelLower) continue;
                                    if (str_contains($vLower, $labelLower) && strlen($v) < 80) continue;

                                    // 2. FILTRO DE TÍTULO (CRÍTICO): Ignorar si empieza con número (3. o 3.- o 3) y es corto
                                    if ($strictHeaderSkip && preg_match('/^\d+[\.\-\s\)]+/', $v)) {
                                        // Si el contenido largo es mayor a 100 caracteres, probablemente es contenido real
                                        if (strlen($v) < 100) continue;
                                    }

                                    // 2. No parece otra etiqueta (contiene :) a menos que sea muy largo
                                    if (str_contains($v, ':') && strlen($v) < 30) continue;

                                    // 3. No es un número de sección solo (ej: "4.-")
                                    if (preg_match('/^\d+[\.\)-]\s*$/', $v)) continue;

                                    return $v;
                                }
                            }
                        }
                    }
                }
                return null;
            };

            // 1. Identificación de la Asignatura
            $val = $searchGrid('modalidad');
            if ($val) $asignatura->modalidad = $val;
            $val = $searchGrid('tipo de curso');
            if ($val) $asignatura->tipo_curso = $val;
            $val = $searchGrid('área de desempeño');
            if ($val) $asignatura->area_desempenio = $val;
            $val = $searchGrid('pre-requisito');
            if ($val) $asignatura->requisitos = $val;

            // Sesiones
            $teoricas = $searchGrid('teóricas:');
            if ($teoricas) $asignatura->sesiones_semanales_teoricas = intval($teoricas);

            $practicas = $searchGrid('prácticas:');
            if ($practicas) $asignatura->sesiones_semanales_practicas = intval($practicas);

            // 2. Docente Responsable
            $val = $searchGrid('email') ?: $searchGrid('correo');
            if ($val) $asignatura->docente_email = $val;
            $val = $searchGrid('formación');
            if ($val) $asignatura->docente_formacion = $val;
            $val = $searchGrid('teléfono');
            if ($val) $asignatura->docente_telefono = $val;

            // 3. Justificación
            $just = $searchGrid('justificación de la asignatura', 15, 10, true);
            if ($just) $asignatura->justificacion = $just;

            // 4. Propósito General
            $prop = $searchGrid('propósito general de la unidad', 15, 10, true);
            if ($prop) $asignatura->proposito_general = $prop;

            // 5. Competencias (CRÍTICO - BÚSQUEDA FUZZY PERO DISTINTA)
            $global = $searchGrid('competencia global específica');
            if ($global) $asignatura->competencia_global_especifica = $global;

            $unidad = $searchGrid('unidad de competencia específica');
            if ($unidad) $asignatura->competencia_asignatura = $unidad;

            // 6. Elementos de Competencia (EXTRACCIÓN ÚNICAMENTE DEL PUNTO 6)
            $ec = [];
            $foundSec6 = false;
            foreach ($rows as $rIdx => $row) {
                $lineStr = mb_strtolower(implode(' ', array_filter($row)));

                if (str_contains($lineStr, '6.- elementos de competencia') || (str_contains($lineStr, 'elementos de competencia') && strlen($lineStr) < 40)) {
                    $foundSec6 = true;
                    continue;
                }

                if ($foundSec6) {
                    // STOP: Detección de siguiente sección (7 u 8)
                    if (preg_match('/^\d+\.-/', trim(implode('', $row))) && !str_contains($lineStr, '6.-')) {
                        // Si detectamos un nuevo número de sección que no sea el 6, salimos.
                        break;
                    }

                    // Buscar "Elemento de competencia X" en cualquier celda de la fila
                    foreach ($row as $cIdx => $cell) {
                        $cellVal = mb_strtolower(trim($cell ?? ''));
                        if (preg_match('/elemento de competencia\s*(\d+)/i', $cellVal, $m)) {
                            $num = intval($m[1]);
                            $foundContent = '';

                            // 1. Buscar en la MISMA FILA a la derecha (Rango corto para evitar saltar a otras etiquetas)
                            for ($dc = 1; $dc < 15; $dc++) {
                                $v = trim($row[$cIdx + $dc] ?? '');
                                if (strlen($v) > 5) {
                                    // NO capturar si es otra etiqueta de sección o de elemento
                                    if (str_contains(mb_strtolower($v), 'elemento de competencia')) continue;
                                    if (preg_match('/^\d+\.-/i', $v)) continue;

                                    $foundContent = $v;
                                    break;
                                }
                            }
                            // El usuario solicitó no buscar en otras filas si la derecha está vacía.

                            if ($foundContent !== '') {
                                $ec[] = $foundContent;
                                // Sincronización con Unidades para la UI
                                $asignatura->unidades()->updateOrCreate(
                                    ['numero' => $num],
                                    [
                                        'elemento_competencia' => $foundContent,
                                        'titulo' => $asignatura->unidades()->where('numero', $num)->value('titulo') ?: "UNIDAD $num"
                                    ]
                                );
                            }
                        }
                    }
                }
            }

            if (!empty($ec)) {
                $asignatura->elementos_competencia = array_values(array_unique($ec));
            }

            // 8. Metodología General
            $metodologia = [];
            // Búsqueda específica para metodologías ignorando etiquetas de "Si corresponde"
            $vAula = $searchGrid('en el aula');
            if ($vAula && strlen($vAula) > 5) $metodologia['aula'] = $vAula;

            $vSim = $searchGrid('centro de simulación');
            if ($vSim && strlen($vSim) > 5) $metodologia['simulacion'] = $vSim;

            $vHosp = $searchGrid('hospital y centros de salud');
            if ($vHosp && strlen($vHosp) > 5) $metodologia['hospital'] = $vHosp;

            if (!empty($metodologia)) {
                $asignatura->metodologia_general = $metodologia;
            }

            // 9. Sistema de Evaluación (EXTRACCIÓN ESTRUCTURADA)
            $evaluacion = [
                'intro' => '',
                'diagnostica' => '',
                'formativa' => '',
                'sumativa' => '',
                'ponderacion' => '',
                'final' => ''
            ];

            foreach ($rows as $rIdx => $row) {
                foreach ($row as $cIdx => $cell) {
                    $cellVal = mb_strtolower(trim($cell ?? ''));
                    if (str_contains($cellVal, '9. sistema de evaluación')) {
                        // BLOQUE 1: Intro y Fases (Suelen estar 2 filas abajo)
                        $rIntro = $rIdx + 2;
                        if (isset($rows[$rIntro])) {
                            $evaluacion['intro'] = trim($rows[$rIntro][1] ?? ''); // Col B

                            $fasesRaw = trim($rows[$rIntro][5] ?? ''); // Col F
                            if ($fasesRaw !== '') {
                                // Split a., b., c. usando delimitadores flexibles
                                if (preg_match('/a\.\s*(.*?)\s+b\.\s*(.*?)\s+c\.\s*(.*)/is', $fasesRaw, $m)) {
                                    $evaluacion['diagnostica'] = trim($m[1]);
                                    $evaluacion['formativa'] = trim($m[2]);
                                    $evaluacion['sumativa'] = trim($m[3]);
                                } else {
                                    $evaluacion['formativa'] = $fasesRaw;
                                }
                            }
                        }

                        // BLOQUE 2: Ponderación y Final (Suelen estar 3-4 filas abajo)
                        $rPond = $rIdx + 3;
                        if (isset($rows[$rPond])) {
                            $fullBlock = trim($rows[$rPond][1] ?? ''); // Col B
                            if (str_contains($fullBlock, 'La evaluación final')) {
                                $parts = explode('La evaluación final', $fullBlock);
                                $evaluacion['ponderacion'] = trim($parts[0]);
                                $evaluacion['final'] = 'La evaluación final ' . trim($parts[1]);
                            } else {
                                $evaluacion['ponderacion'] = $fullBlock;
                            }
                        }
                        break 2;
                    }
                }
            }
            if (!empty(array_filter($evaluacion))) {
                $asignatura->sistema_evaluacion = $evaluacion;
            }

            // 12. Criterios y Normativa (EXTRACCIÓN ESTRUCTURADA)
            $normativaObj = [
                'clase' => '',
                'laboratorio' => ''
            ];

            foreach ($rows as $rIdx => $row) {
                foreach ($row as $cIdx => $cell) {
                    $cellVal = mb_strtolower(trim($cell ?? ''));
                    if (str_contains($cellVal, '12.- criterios y normativa') || str_contains($cellVal, 'reglamento para las clases')) {

                        $allText = "";
                        // Capturamos el bloque de texto (Columnas B-J, filas+1 a +9)
                        for ($dr = 1; $dr <= 9; $dr++) {
                            if (isset($rows[$rIdx + $dr])) {
                                $rowStr = mb_strtolower(implode(' ', array_filter($rows[$rIdx + $dr])));
                                // Si detectamos el inicio de la siguiente sección, paramos
                                if (str_contains($rowStr, '14.- bibliografía')) break;

                                foreach ($rows[$rIdx + $dr] as $cVal) {
                                    $v = trim($cVal ?? '');
                                    if ($v !== '') $allText .= $v . "\n";
                                }
                            }
                        }

                        if ($allText !== "") {
                            // Separamos por el delimitador clave
                            if (str_contains($allText, 'Además, en laboratorio')) {
                                $parts = explode('Además, en laboratorio', $allText);
                                $normativaObj['clase'] = trim($parts[0]);
                                $normativaObj['laboratorio'] = 'Además, en laboratorio' . trim($parts[1]);
                            } else {
                                $normativaObj['clase'] = trim($allText);
                            }
                        }
                        break 2;
                    }
                }
            }

            if ($normativaObj['clase'] !== '' || $normativaObj['laboratorio'] !== '') {
                $asignatura->reglamento_normativa = $normativaObj;
            }

            // 14. Bibliografía (ESTRATEGIA REFORZADA CON DIVISIÓN POR TIPO)
            $especifica = [];
            $complementaria = [];
            $currentMode = ''; // 'basica' or 'complementaria'
            $foundBiblioHeader = false;

            foreach ($rows as $rIdx => $row) {
                $rowCombined = mb_strtolower(implode(' ', array_filter($row)));

                // Detección de cabecera de sección
                if (str_contains($rowCombined, '14.- bibliografía')) {
                    $foundBiblioHeader = true;
                    continue;
                }

                if ($foundBiblioHeader) {
                    // Cambio de modo por sub-cabecera (Específica o Complementaria)
                    if (str_contains($rowCombined, 'específica:')) {
                        $currentMode = 'basica';
                        continue;
                    }
                    if (str_contains($rowCombined, 'complementaria:')) {
                        $currentMode = 'complementaria';
                        continue;
                    }

                    // Stop if next section header (e.g. 15.-)
                    $fullRowStr = trim(implode('', $row));
                    if (preg_match('/^\d+\.-/', $fullRowStr) && !str_contains($rowCombined, '14.-')) {
                        break;
                    }

                    // Captura de contenido de la fila
                    $line = trim(implode(' ', array_filter($row)));

                    // Filtramos ruido: longitud mínima y que no sean los propios encabezados
                    if ($currentMode !== '' && $line !== '' && strlen($line) > 3) {
                        // Evitar capturar accidentalmente el título de la sección
                        if (str_contains(mb_strtolower($line), 'bibliografía') && strlen($line) < 25) continue;

                        if ($currentMode === 'basica') {
                            $especifica[] = $line;
                        } else if ($currentMode === 'complementaria') {
                            $complementaria[] = $line;
                        }
                    }
                }
            }

            if (!empty($especifica) || !empty($complementaria)) {
                $asignatura->bibliografias()->delete();
                if (!empty($especifica)) $this->saveBibliografias($asignatura, $especifica, 'Basica');
                if (!empty($complementaria)) $this->saveBibliografias($asignatura, $complementaria, 'Complementaria');
            }

            // LOG DE RESULTADOS PARA DEPURACIÓN
            \Illuminate\Support\Facades\Log::info("PAC IMPORT SUCCESS: " . $asignatura->id . " | Biblio count: " . (count($especifica) + count($complementaria)));

            $asignatura->save();

            return response()->json(['message' => 'Programa de Asignatura importado con éxito total', 'asignatura' => $asignatura]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Excel PAC Import Error: " . $e->getMessage());
            return response()->json(['error' => 'Error al procesar el PAC Excel: ' . $e->getMessage()], 500);
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

    /**
     * Descargar plantilla Excel para Planificación Personal (Pre-llenada con temas)
     */
    public function templatePersonal($id)
    {
        $asignatura = Asignatura::with(['unidades.temas'])->findOrFail($id);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // 1. Cabeceras
        $headers = [
            'Unidad (#)', 'Tema (#)', 'Título (Referencial)',
            'Estrategias Metodológicas', 'Actividades de Aprendizaje', 'Recursos (1 x línea)',
            'Eval. Formativa: Actividades', 'Eval. Formativa: Instrumentos', 'Eval. Formativa: Evidencias',
            'Eval. Sumativa: Actividades', 'Eval. Sumativa: Instrumentos', 'Eval. Sumativa: Evidencias',
            'Secuencia: Intro (Actividad)', 'Secuencia: Intro (Min)',
            'Secuencia: Resultados (Actividad)', 'Secuencia: Resultados (Min)',
            'Secuencia: Contenido (Actividad)', 'Secuencia: Contenido (Min)',
            'Secuencia: Cuerpo (Actividad)', 'Secuencia: Cuerpo (Min)',
            'Secuencia: Cierre (Actividad)', 'Secuencia: Cierre (Min)'
        ];
        $sheet->fromArray($headers, NULL, 'A1');

        // Estilo cabecera
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '10b981']], // Teal-600
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:V1')->applyFromArray($headerStyle);
        
        foreach (range('A', 'V') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // 2. Pre-llenar datos reales
        $row = 2;
        foreach ($asignatura->unidades->sortBy('numero') as $unidad) {
            foreach ($unidad->temas->sortBy('orden') as $tema) {
                $data = [
                    $unidad->numero,
                    $tema->orden,
                    $tema->titulo,
                    // El resto de columnas vacías para que el docente las llene
                ];
                $sheet->fromArray($data, NULL, 'A' . $row);
                $row++;
            }
        }

        // Si no hay temas, dejar una fila de ejemplo vacía o al menos asegurar el formato
        if ($row == 2) {
            $sheet->setCellValue('A2', '1');
            $sheet->setCellValue('B2', '1');
            $sheet->setCellValue('C2', 'Ejemplo: Tema 1');
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'plantilla_planificacion_' . \Illuminate\Support\Str::slug($asignatura->nombre) . '.xlsx');
    }

    /**
     * Importar Planificación Personal desde Excel
     */
    public function importPersonal(Request $request, $id)
    {
        $asignatura = Asignatura::findOrFail($id);
        $userId = \Illuminate\Support\Facades\Auth::id();

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No se ha subido ningún archivo.'], 400);
        }

        try {
            $file = $request->file('file');
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Saltar cabecera
            array_shift($rows);

            $stats = ['updated' => 0, 'errors' => 0];
            $errors = [];

            foreach ($rows as $index => $row) {
                if (empty($row[0]) || empty($row[1])) continue; // Skip if unit or theme # is missing

                $uNum = intval($row[0]);
                $tNum = intval($row[1]);

                // Buscar el tema en la asignatura actual
                $tema = \App\Models\Tema::whereHas('unidad', function($q) use ($id, $uNum) {
                    $q->where('asignatura_id', $id)->where('numero', $uNum);
                })->where('orden', $tNum)->first();

                if (!$tema) {
                    $stats['errors']++;
                    $errors[] = "Fila " . ($index + 2) . ": No se encontró Unidad $uNum - Tema $tNum en esta asignatura.";
                    continue;
                }

                // Procesar Listas (Recursos, Evaluaciones)
                $parseList = function($val) {
                    if (empty($val)) return [];
                    return array_values(array_filter(preg_split('/\r\n|\r|\n|,/', trim($val)), 'trim'));
                };

                // Construir Secuencia
                $secuencia = [
                    ['momento' => 'INTRODUCCION', 'actividad' => trim($row[12] ?? ''), 'duracion' => intval($row[13] ?? 10)],
                    ['momento' => 'RESULTADOS DE APRENDIZAJE/LOGROS ESPERADOS', 'actividad' => trim($row[14] ?? ''), 'duracion' => intval($row[15] ?? 5)],
                    ['momento' => 'CONTENIDOS DE LA CLASE', 'actividad' => trim($row[16] ?? ''), 'duracion' => intval($row[17] ?? 15)],
                    ['momento' => 'CUERPO DE CONTENIDOS', 'actividad' => trim($row[18] ?? ''), 'duracion' => intval($row[19] ?? 45)],
                    ['momento' => 'CONCLUSION O CIERRE', 'actividad' => trim($row[20] ?? ''), 'duracion' => intval($row[21] ?? 15)],
                ];

                // Update or Create
                \App\Models\PlanificacionPersonal::updateOrCreate(
                    ['tema_id' => $tema->id, 'user_id' => $userId],
                    [
                        'estrategias_metodologicas' => trim($row[3] ?? ''),
                        'estrategias_aprendizaje' => trim($row[4] ?? ''),
                        'estrategias_recursos' => $parseList($row[5] ?? ''),
                        'evaluacion_formativa' => [
                            'actividades' => $parseList($row[6] ?? ''),
                            'instrumentos' => $parseList($row[7] ?? ''),
                            'evidencias' => $parseList($row[8] ?? ''),
                        ],
                        'evaluacion_sumativa' => [
                            'actividades' => $parseList($row[9] ?? ''),
                            'instrumentos' => $parseList($row[10] ?? ''),
                            'evidencias' => $parseList($row[11] ?? ''),
                        ],
                        'secuencia_didactica' => $secuencia
                    ]
                );

                $stats['updated']++;
            }

            return response()->json([
                'message' => 'Proceso completado.',
                'stats' => $stats,
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Import Personal Excel Error: " . $e->getMessage());
            return response()->json(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
        }
    }
}
