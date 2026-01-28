<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Carrera;
use App\Services\University\UniversityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AsignaturaController extends Controller
{
    protected $universityService;
    protected $syncService;

    public function __construct(UniversityService $universityService, \App\Services\AsignaturaSyncService $syncService)
    {
        $this->universityService = $universityService;
        $this->syncService = $syncService;
    }

    /**
     * Lista asignaturas con filtros cascading.
     * GET /api/asignaturas?sede_id=1&carrera_id=5&semestre=3
     */
    public function index(Request $request)
    {
        $query = Asignatura::with(['grupos.docente']);

        // Filtros (Pivote y Texto)
        if ($request->filled('sede_id') || $request->filled('carrera_id') || $request->filled('semestre')) {
            $query->whereHas('carreras', function ($q) use ($request) {
                if ($request->filled('sede_id')) $q->where('asignatura_carrera.sede_id', $request->sede_id);
                if ($request->filled('carrera_id')) $q->where('carreras.id', $request->carrera_id);
                if ($request->filled('semestre')) $q->where('asignatura_carrera.semestre', $request->semestre);
            });

            // Cargar contexto específico para mostrar los datos correctos
            $query->with(['carreras' => function ($q) use ($request) {
                if ($request->filled('sede_id')) $q->where('asignatura_carrera.sede_id', $request->sede_id);
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

            return [
                'id' => $a->id,
                'codigo' => $a->codigo,
                'nombre' => $a->nombre,
                'comun_token' => $a->comun_token, // Added for frontend indicator
                'creditos' => $a->creditos,
                'semestre' => $context?->pivot?->semestre,
                'horas_teoricas' => $a->horas_teoricas,
                'horas_practicas' => $a->horas_practicas,
                'carrera_id' => $context?->id,
                'carrera_nombre' => $context?->nombre ?? 'N/A',
                'sede_id' => $context?->pivot?->sede_id,
                'sede_nombre' => $sedesMap[$context?->pivot?->sede_id] ?? 'N/A',
                'activa' => $a->deleted_at === null,
                'docentes' => $docentes->pluck('nombre_completo')->values(),
                'docente_nombre' => $docentes->isEmpty() ? null : $docentes->pluck('nombre_completo')->implode(', '), // Fix for card display
                'grupos_count' => $a->grupos->count(),
                'docentes_data' => $docentes->map(function ($d) use ($a) { // Para el diálogo de selección
                    // Calcular descripción de grupos para este docente
                    $gruposDocente = $a->grupos->where('docente_id', $d->id);
                    $desc = $gruposDocente->map(fn($g) => $g->nombre . ' (' . $g->tipo . ')')->implode(', ');
                    return [
                        'id' => $d->id,
                        'nombre' => $d->nombre_completo,
                        'descripcion_grupos' => $desc
                    ];
                })->values()
            ];
        }));
    }

    /**
     * Muestra el detalle completo fusionado.
     * Si no existe localmente, lo crea primero (First-Time-Use persistence).
     */
    public function show(Request $request, $codigo)
    {
        // 1. Obtener parámetros opcionales para buscar en API si no existe local
        $branchCode = $request->input('branch_code', 'CBA');
        $careerCode = $request->input('career_code', 'CARELE');

        // 2. Buscar primero en base de datos local (por ID o por Código)
        $query = Asignatura::where('id', $codigo)->orWhere('codigo', $codigo);

        // Determine target user for checks (Self or specific Docente as Director)
        $targetUserId = $request->input('docente_id', Auth::id());

        // Filter Content by Group Type
        if ($request->filled('grupo_id')) {
            $grupo = \App\Models\Grupo::find($request->grupo_id);
            if ($grupo) {
                $query->with(['unidades' => function ($q) use ($grupo, $targetUserId) {
                    $q->forGroup($grupo)->with(['temas' => function ($t) use ($grupo, $targetUserId) {
                        $t->forGroup($grupo)->with([
                            'logros.indicadores',
                            'bibliografias',
                            'planificacionPersonal' => fn($q) => $q->where('user_id', $targetUserId)
                        ]);
                    }]);
                }]);
            } else {
                $query->with([
                    'unidades.temas.logros.indicadores',
                    'unidades.temas.bibliografias',
                    'unidades.temas.planificacionPersonal' => fn($q) => $q->where('user_id', $targetUserId)
                ]);
            }
        } else {
            $query->with([
                'unidades.temas.logros.indicadores',
                'unidades.temas.bibliografias',
                'unidades.temas.planificacionPersonal' => fn($q) => $q->where('user_id', $targetUserId)
            ]);
        }

        $local = $query->with(['bibliografias', 'docentes', 'carreras.sede'])->first();

        // 3. Si existe localmente, retornamos eso (con alias para el frontend)
        if ($local) {
            // Contexto principal (usamos la primera carrera encontrada o la que venga en el input)
            $mainCarrera = $local->carreras->first();

            // Fallback: Si no hay relación en pivote, usar carrera_id directo (Legacy Data Fix)
            if (!$mainCarrera && $local->carrera_id) {
                $mainCarrera = \App\Models\Carrera::with('sede')->find($local->carrera_id);
            }

            // EMERGENCY FALLBACK: Si aún así no hay carrera (Datahuérfana), buscar por "Systems Engineering" o default a ID 1 (Sistemas CBBA)
            // Esto es necesario para registros antiguos migrados incorrectamente.
            if (!$mainCarrera) {
                // Try to infer from user context? No, too risky.
                // Default to "Sistemas Cochabamba" (ID 5 usually) if the subject seems to be linked to user.
                // Better: Inject a "Dummy" context with Sede 1 to allow import.
                $mainCarrera = new \stdClass();
                $mainCarrera->id = 0;
                $mainCarrera->nombre = 'Sin Carrera Asignada (Legacy)';
                $mainCarrera->sede_id = 1; // Assume Central for unassigned subjects to allow fixing
                $mainCarrera->sede = new \stdClass();
                $mainCarrera->sede->id = 1;
                $mainCarrera->sede->nombre = 'Sede Central (Inferred)';
                $mainCarrera->pivot = new \stdClass();
                $mainCarrera->pivot->semestre = $local->semestre;
                $mainCarrera->pivot->sede_id = 1; // Ensure pivot has value too
            }

            // AUTO-SYNC: Si la asignatura no tiene unidades...
            if ($local->unidades()->count() === 0) {
                // ... (código existente de sync service) ...
                $actualBranchCode = $mainCarrera->sede->codigo ?? $branchCode; // Use ->sede->codigo safely
                $actualCareerCode = $mainCarrera->codigo ?? $careerCode;
                $this->syncService->syncAnalyticalProgram($local, $actualBranchCode, $actualCareerCode);
                $local->load(['unidades.temas', 'bibliografias', 'docentes']);
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

            // Explicit sede_id injection. PRIORITY: Pivot > Career > Fallback
            $response['sede_id'] = $mainCarrera?->pivot?->sede_id ?? $mainCarrera?->sede_id ?? 1;


            // Horarios desde la estructura normalizada (grupos + horarios)
            $response['horarios_data'] = $local->grupos()
                ->with(['docente:id,nombre_completo', 'horarios.aula:id,nombre'])
                ->get()
                ->map(function ($grupo) {
                    return [
                        'id' => $grupo->id,
                        'grupo' => $grupo->nombre,
                        'tipo' => $grupo->tipo,
                        'docente_nombre' => $grupo->docente?->nombre_completo,
                        'horarios' => $grupo->horarios->map(function ($h) {
                            return [
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

        // 4. Si NO existe localmente, consultamos a la API y CREAMOS la asignatura localmente (Sync On Demand)
        try {
            $program = $this->universityService->getAnalyticalProgram($codigo, $branchCode, $careerCode);

            if (!$program) {
                return response()->json(['message' => 'No encontrado en API ni localmente.'], 404);
            }

            // Crear Asignatura Local
            $newAsignatura = Asignatura::create([
                'codigo' => $codigo,
                'nombre' => $program['title'] ?? $program['identification']['name'] ?? 'Desconocido',
                'creditos' => $program['identification']['credits'] ?? 0,
                'semestre' => $program['identification']['semester'] ?? 1,
                // Llenar otros campos por defecto si es necesario
            ]);

            // Poblar Carrera (Hack: Asignar a la primera carrera que coincida con el codigo o crear dummy)
            // Por ahora asumimos que la relación carrera se maneja aparte o se inferirá después.
            // Ojo: Asignatura requires 'carrera_id'. Necesitamos manejar esto.
            // Para evitar errores, buscamos la carrera por codigo 'careerCode'
            $carrera = Carrera::where('codigo', $careerCode)->first();
            if ($carrera) {
                $newAsignatura->carrera_id = $carrera->id;
                $newAsignatura->save();
            }

            // Sync contenido recursivo
            $this->syncService->syncAnalyticalProgram($newAsignatura, $branchCode, $careerCode, true, $program);

            return $this->show($request, $codigo); // Llamada recursiva para retornar formato local estandar

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error syncing asignatura: ' . $e->getMessage());
            return response()->json(['error' => 'Error al sincronizar con API: ' . $e->getMessage()], 503);
        }
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
            'sesiones_semanales_practicas'
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

        // Validación de permisos: Solo Cochabamba (ID 1)
        // Resolución robusta de Sede (Pivot > Carrera > Legacy Fallback > Default 1)
        $firstCarrera = $asignatura->carreras->first();
        $sedeId = $firstCarrera?->pivot?->sede_id ?? $firstCarrera?->sede_id;

        if (!$sedeId && $asignatura->carrera_id) {
            $c = \App\Models\Carrera::find($asignatura->carrera_id);
            $sedeId = $c?->sede_id;
        }

        // Si no se detecta sede (Legacy/Huérfana), asumir Sede 1 para permitir gestión
        if (!$sedeId) $sedeId = 1;

        if ($sedeId != 1) {
            return response()->json(['error' => 'La importación solo está permitida para la Sede Central (Cochabamba).'], 403);
        }

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No se ha subido ningún archivo.'], 400);
        }

        try {
            $data = $parser->parseWord($request->file('file'));

            // Flags de importación (Default true para compatibilidad)
            $importDatos = $request->boolean('import_datos', true);
            $importUnidades = $request->boolean('import_unidades', true);
            $importBiblio = $request->boolean('import_bibliografia', true);

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
                                'orden' => $i + 1,
                                'horas' => 0
                            ]);
                        }
                    }
                }
                $importedUnits = true;
            }

            // FALLBACK: Elementos de competencia simples (si no se detectó estructura compleja)
            if ($importUnidades && !$importedUnits && !empty($data['elementos_competencia_por_unidad'])) {
                foreach ($data['elementos_competencia_por_unidad'] as $numero => $ecText) {
                    // Buscar o crear la unidad
                    $unidad = $asignatura->unidades()->firstOrCreate(
                        ['numero' => $numero],
                        [
                            'titulo' => "UNIDAD DE APRENDIZAJE $numero",
                            'horas' => 0
                        ]
                    );

                    // Actualizar siempre el EC importado
                    $unidad->elemento_competencia = $ecText;
                    $unidad->save();
                }
            }
            // Wait, the parser output $data usually contains 'unidades' array if implemented fully.
            // Logic for clearing/syncing units should be here if parser provided it.
            // Assuming specific current implementation only maps EC per unit.

            return response()->json(['message' => 'Importación exitosa', 'data' => $data]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Import Error: " . $e->getMessage());
            return response()->json(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
        }
    }

    private function saveBibliografias(Asignatura $asignatura, array $lines, $tipo)
    {
        foreach ($lines as $line) {
            try {
                $line = trim($line);
                if (empty($line)) continue;

                // Truncado estricto a 180 caracteres
                $titulo = substr($line, 0, 180);
                $descripcion = (strlen($line) > 180) ? $line : null;

                $asignatura->bibliografias()->create([
                    'titulo' => $titulo,
                    'descripcion' => $descripcion,
                    'tipo' => $tipo,
                    'autor' => 'AA.VV.',
                    'anio' => 'S/F',
                    'editorial' => 'S/E'
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Error guardando bibliografia '$line': " . $e->getMessage());
            }
        }
    }
}
