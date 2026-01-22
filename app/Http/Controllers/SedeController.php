<?php

namespace App\Http\Controllers;

use App\Models\Sede;
use App\Models\Grupo;
use Illuminate\Http\Request;

class SedeController extends Controller
{
    public function index()
    {
        $sedes = Sede::query()
            ->withCount(['carreras'])
            ->orderBy('id')
            ->get()
            ->map(function ($sede) {
                // Contar docentes reales a través de grupos usando la relación Many-to-Many
                // Docente -> Grupos -> Asignatura -> Carreras -> Sedes
                $docentesCount = \App\Models\Docente::whereHas('grupos.asignatura.carreras.sedes', function ($q) use ($sede) {
                    $q->where('sedes.id', $sede->id);
                })->count();

                return [
                    'id' => $sede->id,
                    'nombre' => $sede->nombre,
                    'codigo' => $sede->codigo,
                    'ciudad' => $sede->ciudad,
                    'activo' => $sede->activo,
                    'carreras_count' => $sede->carreras_count,
                    'docentes_count' => $docentesCount,
                ];
            });

        // Global Stats
        $globalStats = [
            'total_sedes' => $sedes->count(),
            'sedes_activas' => $sedes->where('activo', true)->count(),
            // Distinct carreras across all sedes (approximated by sum if uniqueness is per-sede, or DB query for true distinct)
            // 'total_carreras' => \App\Models\Carrera::count(), // Total carreras in system
            // 'total_carreras' => $sedes->sum('carreras_count'), // Total assignments (one carrera can be in multiple sedes)
            'total_carreras' => \App\Models\Carrera::count(),
            'total_docentes' => \App\Models\Docente::count()
        ];

        return response()->json([
            'data' => $sedes,
            'stats' => $globalStats
        ]);
    }

    public function show($id)
    {
        return Sede::with('carreras')->findOrFail($id);
    }

    /**
     * Obtener carreras de una sede específica (para cascading filters)
     */
    public function carreras($id)
    {
        $sede = Sede::findOrFail($id);

        // Carreras por relación directa o por pivot
        $carreras = \App\Models\Carrera::where('sede_id', $id)
            ->orWhereHas('sedes', fn($q) => $q->where('sede_id', $id))
            ->get(['id', 'nombre', 'sigla', 'codigo']);

        return response()->json($carreras);
    }
}
