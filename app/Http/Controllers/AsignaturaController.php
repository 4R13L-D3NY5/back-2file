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

    public function __construct(UniversityService $service)
    {
        $this->universityService = $service;
    }

    /**
     * Sincroniza y lista asignaturas.
     * Este método actúa como la "Fachada": 40% Local, 60% API Externa
     */
    public function index(Request $request)
    {
        // 1. Obtener parámetros (Default: CBA/CARSIS si no se envían)
        $branchCode = $request->input('branch_code', 'CBA'); 
        $careerCode = $request->input('career_code', 'CARELE'); 

        if (!$branchCode || !$careerCode) {
            return response()->json(['error' => 'Faltan parámetros branch_code o career_code'], 400);
        } 
        
        try {
            // Llamada a University Service
            $externalCourses = $this->universityService->getCourses($branchCode, $careerCode);
            
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
                        'origen' => 'API_ONLY'
                    ];
                } else {
                    $fusedData[] = [
                        'id' => $local->id,
                        'codigo' => $local->codigo,
                        'nombre' => $local->nombre, // O el del API si queremos frescura
                        'creditos' => $local->creditos,
                        'semestre' => $local->semestre,
                        
                        // Datos Locales Extendidos
                        'justificacion' => $local->justificacion ? 'Cargado' : null,
                        'avance_unidad' => $local->unidades()->count(),
                        'origen' => 'HYBRID'
                    ];
                }
            }
            
            return response()->json($fusedData);

        } catch (\Exception $e) {
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

        // 2. Buscar primero en base de datos local
        $local = Asignatura::where('codigo', $codigo)
            ->with(['unidades', 'bibliografias', 'docente']) // Eager loading
            ->first();

        // 3. Si existe localmente, retornamos eso (con alias para el frontend)
        if ($local) {
            $response = $local->toArray();
            // Inyectar alias para que el formulario se llene solo
            $response['objetivo_general'] = $local->proposito_general;
            $response['saberes_previos'] = $local->requisitos;
            $response['metodologia_ensenanza'] = $local->metodologia_general;
            $response['criterios_evaluacion'] = $local->sistema_evaluacion;
            
            return response()->json($response);
        }

        // 4. Si NO existe localmente, consultamos a la API del University 
        // para obtener el "Programa Analítico" y mostrárselo al usuario (preview).
        try {
            $program = $this->universityService->getAnalyticalProgram($codigo, $branchCode, $careerCode);
            
            if (!$program) {
                return response()->json(['message' => 'No encontrado en API ni localmente.'], 404);
            }

            // Mapeamos respuesta de la API a una estructura similar a la local
            return response()->json([
                'id' => null, // Indica que no está guardado
                'codigo' => $codigo,
                'nombre' => $program['identification']['name'] ?? 'Desconocido',
                'creditos' => $program['identification']['credits'] ?? 0,
                'semestre' => $program['identification']['semester'] ?? null,
                'programa_analitico_preview' => $program, // Data cruda del API
                'origen' => 'API_PREVIEW'
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al consultar API: ' . $e->getMessage()], 503);
        }
    }
    
    /**
     * Actualizar campos extendidos (Justificación, Metodología, etc).
     */
    public function update(Request $request, $id)
    {
        $local = Asignatura::findOrFail($id);
        
        // Frontend -> DB Mapping
        $data = $request->only([
            'sigla', 'descripcion', 'creditos', 'semestre', 'nombre',
            'horas_teoricas', 'horas_practicas', 'horas_laboratorio',
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
