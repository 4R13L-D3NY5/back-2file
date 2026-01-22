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

        $carreras = $query->get()->map(function ($carrera) {
            return [
                'id' => $carrera->id,
                'nombre' => $carrera->nombre,
                'codigo' => $carrera->codigo ?: $carrera->sigla, // Fallback to sigla
                'sigla' => $carrera->sigla,
                'sede_id' => $carrera->sede_id,
                'activo' => $carrera->activo ?? true,
                'area' => $carrera->area,
                // Stats
                'asignaturas_count' => $carrera->asignaturas_count,
                'docentes_count' => $carrera->docentes()->count(), // Execute Builder count
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

        $asignaturas = $query->get(['id', 'codigo', 'nombre', 'semestre']);

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
}
