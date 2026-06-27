<?php

namespace App\Http\Controllers;

use App\Models\Carrera;
use Illuminate\Http\Request;

class CarreraController extends Controller
{
    /**
     * Listar carreras con filtros opcionales.
     * GET /api/carreras?sede_id=1
     */
    public function index(Request $request)
    {
        $query = Carrera::query()
            ->withCount(['asignaturas']);

        // Filtrar por sede (via relación directa o pivot)
        if ($request->has('sede_id') && $request->sede_id) {
            $sedeId = $request->sede_id;
            $query->where(function ($q) use ($sedeId) {
                $q->where('sede_id', $sedeId)
                    ->orWhereHas('sedes', fn($sub) => $sub->where('sede_id', $sedeId));
            });
        }

        $carreras = $query->with('sedes:id')->get()->map(function ($carrera) {
            // Calcular progreso de documentación
            // Basado en porcentaje de temas completados vs total de temas
            $progreso = 0;
            try {
                $totalTemas = \DB::table('temas')
                    ->join('cronogramas', 'temas.cronograma_id', '=', 'cronogramas.id')
                    ->join('asignatura_carrera', 'cronogramas.asignatura_id', '=', 'asignatura_carrera.asignatura_id')
                    ->where('asignatura_carrera.carrera_id', $carrera->id)
                    ->count();
                
                $temasCompletados = \DB::table('temas')
                    ->join('cronogramas', 'temas.cronograma_id', '=', 'cronogramas.id')
                    ->join('asignatura_carrera', 'cronogramas.asignatura_id', '=', 'asignatura_carrera.asignatura_id')
                    ->where('asignatura_carrera.carrera_id', $carrera->id)
                    ->whereNotNull('temas.fecha_real')
                    ->count();
                
                $progreso = $totalTemas > 0 ? round(($temasCompletados / $totalTemas) * 100) : 0;
            } catch (\Exception $e) {
                // Si hay error en el cálculo, retornar 0
                $progreso = 0;
            }

            return [
                'id' => $carrera->id,
                'nombre' => $carrera->nombre,
                'codigo' => $carrera->codigo ?: $carrera->sigla, // Fallback to sigla
                'sigla' => $carrera->sigla,
                'sede_id' => $carrera->sede_id,
                'sedes_ids' => $carrera->sedes->pluck('id')->toArray(), // Multi-sede support
                'activo' => $carrera->activo ?? true,
                'area' => $carrera->area,
                // Stats
                'asignaturas_count' => $carrera->asignaturas_count,
                'docentes_count' => $carrera->docentes()->count(), // Execute Builder count
                'progreso_documentacion' => $progreso,
            ];
        });

        return response()->json($carreras);
    }

    /**
     * Obtener asignaturas de una carrera (para cascading filters)
     */
    public function asignaturas($id, Request $request)
    {
        $carrera = Carrera::findOrFail($id);

        $query = $carrera->asignaturas();

        // Filtrar por semestre si se proporciona
        if ($request->has('semestre') && $request->semestre) {
            $query->where('semestre', $request->semestre);
        }

        $asignaturas = $query->get(['asignaturas.id', 'asignaturas.codigo', 'asignaturas.nombre', 'asignatura_carrera.semestre']);

        return response()->json($asignaturas);
    }

    /**
     * Obtener lista única de semestres de una carrera
     */
    public function semestres($id)
    {
        $semestres = Carrera::findOrFail($id)
            ->asignaturas()
            ->distinct()
            ->orderBy('semestre')
            ->pluck('semestre')
            ->filter()
            ->values();

        return response()->json($semestres);
    }
    /**
     * Obtener detalles de una carrera
     */
    public function show($id)
    {
        return Carrera::findOrFail($id);
    }

    /**
     * Actualizar contexto de la carrera (Misión, Visión, Perfil)
     */
    public function updateContexto(Request $request, $id)
    {
        $carrera = Carrera::findOrFail($id);

        $data = $request->validate([
            'mision' => 'nullable|string',
            'vision' => 'nullable|string',
            'perfil_profesional' => 'nullable|string',
            'area' => 'nullable|string'
        ]);

        $carrera->update($data);

        return response()->json([
            'message' => 'Información actualizada correctamente',
            'carrera' => $carrera
        ]);
    }

    /**
     * Crear una nueva carrera.
     * POST /api/carreras
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'codigo' => 'required|string|max:20|unique:carreras,codigo',
            'sigla' => 'nullable|string|max:10',
            'sede_id' => 'nullable|exists:sedes,id',
            'facultad' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:100',
            'plan_estudios' => 'nullable|string|in:N,A',
            'activo' => 'boolean',
            'modificado_localmente' => 'nullable|boolean',
        ]);

        // Establecer modificado_localmente en true por defecto para creación local
        if (!isset($validated['modificado_localmente'])) {
            $validated['modificado_localmente'] = true;
        }

        $carrera = Carrera::create($validated);

        return response()->json($carrera, 201);
    }

    /**
     * Actualizar una carrera existente.
     * PUT /api/carreras/{id}
     */
    public function update(Request $request, $id)
    {
        $carrera = Carrera::findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'codigo' => 'sometimes|string|max:20|unique:carreras,codigo,' . $carrera->id,
            'sigla' => 'nullable|string|max:10',
            'sede_id' => 'nullable|exists:sedes,id',
            'facultad' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:100',
            'plan_estudios' => 'nullable|string|in:N,A',
            'activo' => 'sometimes|boolean',
            'modificado_localmente' => 'nullable|boolean',
        ]);

        // Marcar como modificado localmente al actualizar
        $validated['modificado_localmente'] = true;

        $carrera->update($validated);

        return response()->json($carrera);
    }

    /**
     * Eliminar una carrera (soft delete).
     * DELETE /api/carreras/{id}
     */
    public function destroy($id)
    {
        $carrera = Carrera::findOrFail($id);
        $carrera->delete();

        return response()->json(null, 204);
    }
}
