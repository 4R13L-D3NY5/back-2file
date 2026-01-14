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
     * Sincroniza y lista asignaturas.
     * Este método actúa como la "Fachada": 40% Local, 60% API Externa
     */
    public function index(Request $request)
    {
        // 1. Obtener parámetros (Si no se envían, retornamos data local)
        $branchCode = $request->input('branch_code');
        $careerCode = $request->input('career_code');

        if (isset($localAsignaturas)) {
            // If we already loaded locals (e.g. no filters), ensure we respect eager loading if requested
            // Note: $localAsignaturas above might not have loaded them if we stick to lines 32/35.
            // Let's refactor the initial load or lazily load here if needed.
            // Or better: Modify the query above.
        }

        // REFACTORING QUERY LOGIC TO SUPPORT EAGER LOADING
        $query = Asignatura::with(['carrera.sede']);

        if ($request->has('include_details') && $request->include_details) {
            $query->with(['unidades.temas', 'bibliografias']);
        }

        if ($branchCode && !$careerCode) {
            $query->whereHas('carrera.sede', function ($q) use ($branchCode) {
                $q->where('codigo', $branchCode);
            });
        }

        // If filtering by career...
        if ($careerCode) {
            $query->whereHas('carrera', function ($q) use ($careerCode) {
                $q->where('codigo', $careerCode);
            });
        }

        // Search Filter
        if ($request->has('search') && $request->search) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('nombre', 'like', "%{$term}%")
                    ->orWhere('codigo', 'like', "%{$term}%");
            });
        }

        // Limit only if not specific (dashboard needs all? or pagination?)
        // For stats we might need all, but 20k is safe for now.
        $localAsignaturas = $query->limit(1000)->get();

        if (isset($localAsignaturas)) {
            return response()->json($localAsignaturas->map(function ($a) {
                $base = [
                    'id' => $a->id,
                    'codigo' => $a->codigo,
                    'nombre' => $a->nombre,
                    'creditos' => $a->creditos,
                    'semestre' => $a->semestre,
                    'horas_teoricas' => $a->horas_teoricas,
                    'horas_practicas' => $a->horas_practicas,
                    'carrera_nombre' => $a->carrera->nombre ?? 'N/A',
                    'sede_nombre' => $a->carrera->sede->nombre ?? \App\Models\Sede::find($a->carrera->sede_id ?? 0)?->nombre ?? 'N/A',
                    'origen' => 'LOCAL_' . ($a->carrera->sede->codigo ?? 'UNKNOWN')
                ];

                if ($a->relationLoaded('unidades')) {
                    $base['unidades'] = $a->unidades; // Serializes recursively (temas)
                }
                // Or just mapped counts if we wanted lightweight, but frontend uses .length on array
                if ($a->relationLoaded('bibliografias')) {
                    $base['bibliografias'] = $a->bibliografias;
                }

                return $base;
            }));
        }

        try {
            // Llamada a University Service
            $externalCoursesRaw = $this->universityService->getCourses($branchCode, $careerCode);
            // Deduplicar por código (el API retorna grupos múltiples)
            $externalCourses = collect($externalCoursesRaw)->unique('courseCode')->values()->all();

            // Buscar nombre de la sede si tenemos el código
            $sedeNameFilter = 'N/A';
            if ($branchCode) {
                $sedeObj = \App\Models\Sede::where('codigo', $branchCode)->first();
                if ($sedeObj) {
                    $sedeNameFilter = $sedeObj->nombre;
                }
            }

            // Transformar/Fusionar data en memoria o DB local bajo demanda
            $fusedData = [];

            foreach ($externalCourses as $external) {
                // Mapeo seguro de llaves (API vs Local)
                $code = $external['courseCode'] ?? $external['code'] ?? null;
                $name = $external['courseName'] ?? $external['name'] ?? 'Sin Nombre';

                if (!$code) continue; // Skip bad data

                // Buscamos si ya tenemos una "copia" local extendida
                $local = Asignatura::where('codigo', $code)->first();

                if (!$local) {
                    // Si no existe, podemos retornarlo tal cual del API o crearlo al vuelo.
                    // Para este MVP, retornamos la estructura mixta.
                    $fusedData[] = [
                        'id' => null, // No persistido aún
                        'codigo' => $code,
                        'nombre' => $name,
                        'creditos' => $external['credits'] ?? 0,
                        'semestre' => $external['semester'] ?? 0,
                        'horas_teoricas' => $external['theoryHours'] ?? 0,
                        'horas_practicas' => $external['practiceHours'] ?? 0,
                        'origen' => 'API_ONLY',
                        'carrera_nombre' => 'API (No guardado)',
                        'sede_nombre' => $sedeNameFilter != 'N/A' ? $sedeNameFilter : 'API'
                    ];
                } else {
                    $fusedData[] = [
                        'id' => $local->id,
                        'codigo' => $local->codigo,
                        'nombre' => $local->nombre, // O el del API si queremos frescura
                        'creditos' => $local->creditos,
                        'semestre' => $local->semestre,
                        'horas_teoricas' => $local->horas_teoricas,
                        'horas_practicas' => $local->horas_practicas,
                        'carrera_nombre' => $local->carrera->nombre ?? 'N/A',
                        'sede_nombre' => $local->carrera->sede->nombre ?? $sedeNameFilter, // Fallback al filtro

                        // Datos Locales Extendidos
                        'justificacion' => $local->justificacion ? 'Cargado' : null,
                        'avance_unidad' => $local->unidades()->count(),
                        'origen' => 'HYBRID'
                    ];
                }
            }

            return response()->json($fusedData);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error fetching asignaturas: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 503);
        }
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
        $local = Asignatura::where('id', $codigo)
            ->orWhere('codigo', $codigo)
            ->with(['unidades', 'bibliografias', 'docentes', 'carrera.sede']) // Eager loading
            ->first();

        // 3. Si existe localmente, retornamos eso (con alias para el frontend)
        if ($local) {
            // AUTO-SYNC: Si la asignatura no tiene unidades (está vacía), intentamos poblarla desde la API
            if ($local->unidades()->count() === 0) {
                // Determinar códigos correctos desde la relación local si existen
                $actualBranchCode = $local->carrera->sede->codigo ?? $branchCode;
                $actualCareerCode = $local->carrera->codigo ?? $careerCode;

                $this->syncService->syncAnalyticalProgram($local, $actualBranchCode, $actualCareerCode);

                // Recargar para mostrar los nuevos datos
                $local->load(['unidades.temas', 'bibliografias', 'docentes']);
            }

            $response = $local->toArray();
            // Inyectar alias para que el formulario se llene solo
            $response['objetivo_general'] = $local->proposito_general;
            $response['saberes_previos'] = $local->requisitos;
            $response['metodologia_ensenanza'] = $local->metodologia_general;
            $response['criterios_evaluacion'] = $local->sistema_evaluacion;

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
            $this->syncAnalyticalProgram($newAsignatura, $branchCode, $careerCode, $program);

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
            'contenido_minimo'
        ]);

        // Mapeo manual de llaves inconsistentes
        if ($request->has('objetivo_general')) $local->proposito_general = $request->objetivo_general;
        if ($request->has('saberes_previos')) $local->requisitos = $request->saberes_previos;
        if ($request->has('metodologia_ensenanza')) $local->metodologia_general = $request->metodologia_ensenanza;
        if ($request->has('criterios_evaluacion')) $local->sistema_evaluacion = $request->criterios_evaluacion;
        if ($request->has('contenido_minimo')) $local->contenido_minimo = $request->contenido_minimo; // Direct but explicit

        $local->fill($data); // Fill the rest
        $local->save();

        return response()->json($local);
    }
}
