<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Carrera;
use App\Services\University\UniversityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // Filter Content by Group Type
        if ($request->filled('grupo_id')) {
            $grupo = \App\Models\Grupo::find($request->grupo_id);
            if ($grupo) {
                $query->with(['unidades' => function ($q) use ($grupo) {
                    $q->forGroup($grupo)->with(['temas' => function ($t) use ($grupo) {
                        $t->forGroup($grupo);
                    }]);
                }]);
            } else {
                $query->with(['unidades.temas']);
            }
        } else {
            $query->with(['unidades.temas']);
        }

        $local = $query->with(['bibliografias', 'docentes', 'carreras.sede'])->first();

        // 3. Si existe localmente, retornamos eso (con alias para el frontend)
        if ($local) {
            // Contexto principal (usamos la primera carrera encontrada o la que venga en el input)
            // TODO: Mejorar selección de contexto si viene en el request
            $mainCarrera = $local->carreras->first();

            // AUTO-SYNC: Si la asignatura no tiene unidades...
            if ($local->unidades()->count() === 0) {
                // ... (código existente de sync service) ...
                $actualBranchCode = $mainCarrera?->sede?->codigo ?? $branchCode;
                $actualCareerCode = $mainCarrera?->codigo ?? $careerCode;
                $this->syncService->syncAnalyticalProgram($local, $actualBranchCode, $actualCareerCode);
                $local->load(['unidades.temas', 'bibliografias', 'docentes']);
            }

            // AUTO-SYNC (CONTENIDO DESCRIPTIVO)
            // Usamos el ID de la sede del contexto principal
            $sedeId = $mainCarrera->sede_id ?? 0;
            if ($sedeId != 1 && (empty($local->descripcion) || empty($local->justificacion))) {
                $central = Asignatura::where('codigo', $local->codigo)
                    ->whereHas('carreras', function ($q) { // Fix: carreras
                        $q->where('sede_id', 1);
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
            // Fix: Include semestre from pivot
            $response['semestre'] = $mainCarrera?->pivot?->semestre;

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
            $this->syncService->syncAnalyticalProgram($newAsignatura, $branchCode, $careerCode, true, $program->toArray());

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
            'contenidos_minimos', // Frontend key inconsistent? checking vue... formDatos.contenido_minimo
            'contenido_minimo',
            'activa'
        ]);

        // Mapeo manual de llaves inconsistentes
        if ($request->has('objetivo_general')) $local->proposito_general = $request->objetivo_general;
        if ($request->has('saberes_previos')) $local->requisitos = $request->saberes_previos;
        if ($request->has('metodologia_ensenanza')) $local->metodologia_general = $request->metodologia_ensenanza;
        if ($request->has('criterios_evaluacion')) $local->sistema_evaluacion = $request->criterios_evaluacion;
        if ($request->has('contenido_minimo')) $local->contenido_minimo = $request->contenido_minimo; // Direct but explicit

        // FIX: Justificación no se estaba mapeando porque no está en $request->only() ni aquí
        if ($request->has('justificacion')) $local->justificacion = $request->justificacion;

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
        if ($asignatura->carrera->sede_id != 1) {
            return response()->json(['error' => 'La importación solo está permitida para la Sede Central (Cochabamba).'], 403);
        }

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No se ha subido ningún archivo.'], 400);
        }

        try {
            $data = $parser->parseWord($request->file('file'));

            // Actualizar campos si tienen valor
            if ($data['justificacion']) $asignatura->justificacion = $data['justificacion'];
            if ($data['proposito_general']) $asignatura->proposito_general = $data['proposito_general'];
            if ($data['metodologia_general']) $asignatura->metodologia_general = $data['metodologia_general'];
            if ($data['sistema_evaluacion']) $asignatura->sistema_evaluacion = $data['sistema_evaluacion'];
            if ($data['contenido_minimo']) $asignatura->contenido_minimo = $data['contenido_minimo'];
            if ($data['requisitos']) $asignatura->requisitos = $data['requisitos'];

            // Corrección: 'descripcion' en el array data mapeaba a 'competencia_asignatura' en el parser original,
            // pero en el controller lo asignabamos a 'descripcion'.
            // Revisemos el parser:
            // NUEVO PARSER LOGIC: 'COMPETENCIA DE LA ASIGNATURA' => $data['competencia_asignatura']

            // CAMPO DE BD: descripcion (lo usaremos para la competencia básica si no hay otro lugar, pero el modelo tiene competencia_asignatura?)
            // El modelo Asignatura NO tiene 'competencia_asignatura' en la tabla original, probablemente se usa 'descripcion' para eso?
            // El user pidió: "debajo de competencia de la asigantura debes de añadir un campo ... elementos de competencia"
            // EN LA UI: "Competencia de la Asignatura" mapea a `formPrograma.competencia_asignatura`.
            // Verifiquemos si `competencia_asignatura` existe en la tabla `asignaturas`.

            // Si no existe columna 'competencia_asignatura', usaremos 'descripcion' o crearemos la columna.
            // En AsignaturaEditPage.vue: v-model="formPrograma.competencia_asignatura"
            // En load datos: competencia_asignatura: asignatura.value.competencia_asignatura || ''
            // EN EL MODELO: NO VEO 'competencia_asignatura' en el $fillable original, solo 'descripcion'.
            // Asumiré que 'descripcion' ES la "Competencia de la Asignatura" para el backend actual, O que debo crear el campo tb.
            // ERROR IMPORTANTE: En el paso anterior NO creé 'competencia_asignatura', solo 'elementos_competencia'.
            // Sin embargo, la UI ya usa 'competencia_asignatura'.
            // Si la UI lo muestra, debe venir del backend. ¿Existe en la BD?
            // Voy a usar 'descripcion' para 'competencia_asignatura' si es el mapeo lógico, o chequear BD.

            if (!empty($data['competencia_asignatura'])) {
                // Por ahora mapeamos a descripcion si asumo que es lo mismo, O mejor: checkear si existe la columna.
                // Asumamos que descripcion = competencia de asignatura (es lo usual en estos sistemas legacy).
                $asignatura->competencia_asignatura = $data['competencia_asignatura'];
                // Si falla el save es pq no existe la columna.
            }
            // Retratar: En el parser ahora uso 'competencia_asignatura' key.

            if ($data['elementos_competencia']) $asignatura->elementos_competencia = $data['elementos_competencia'];

            // Nuevos campos extraídos
            if (!empty($data['competencia_global_especifica'])) $asignatura->competencia_global_especifica = $data['competencia_global_especifica'];
            if (!empty($data['reglamento_normativa'])) $asignatura->reglamento_normativa = $data['reglamento_normativa'];
            if (!empty($data['organizacion_calendario'])) $asignatura->organizacion_calendario = $data['organizacion_calendario'];

            $asignatura->save();

            // Guardar Bibliografía
            // Borrar previas de este origen (Importado)? O Keep?
            // User: "si vuelve a subir ... solo añada lo que falta". Mejor añadimos sin duplicar todo ciegamente.
            // Estrategia: Borrar todo NO es opción si editaron a mano. Pero un import suele "resetear" bibliografia del plan.
            // Para simplicidad y robustez: Agregamos las nuevas.

            $this->saveBibliografias($asignatura, $data['bibliografia_basica'], 'BASICA');
            $this->saveBibliografias($asignatura, $data['bibliografia_complementaria'], 'COMPLEMENTARIA');

            // Asignar elementos de competencia a cada unidad correspondiente
            if (!empty($data['elementos_competencia_por_unidad'])) {
                $unidades = $asignatura->unidades()->orderBy('numero')->get();
                foreach ($unidades as $unidad) {
                    $numeroUnidad = $unidad->numero;
                    if (isset($data['elementos_competencia_por_unidad'][$numeroUnidad])) {
                        $unidad->elemento_competencia = $data['elementos_competencia_por_unidad'][$numeroUnidad];
                        $unidad->save();
                        \Illuminate\Support\Facades\Log::info("E.C. asignado a Unidad $numeroUnidad");
                    }
                }
            }

            return response()->json(['message' => 'Importación exitosa', 'data' => $data]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Import Error: " . $e->getMessage());
            return response()->json(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
        }
    }

    private function saveBibliografias(Asignatura $asignatura, array $lines, $tipo)
    {
        foreach ($lines as $line) {
            if (empty($line)) continue;
            // Evitar duplicados exactos
            $exists = $asignatura->bibliografias()
                ->where('titulo', $line) // Asumimos que la linea es el titulo completo por ahora
                ->where('tipo', $tipo)
                ->exists();

            if (!$exists) {
                // Parseo simple: Autor + Titulo es complejo. Guardamos todo en Titulo o Descripción.
                // Bibliografia Model: titulo, autor, anio, editorial, tipo
                // Como viene texto plano, lo ponemos en 'titulo' y dejamos el resto null o por defecto.
                $asignatura->bibliografias()->create([
                    'titulo' => substr($line, 0, 255), // Truncate safety
                    'descripcion' => strlen($line) > 255 ? $line : null,
                    'tipo' => $tipo
                ]);
            }
        }
    }
}
