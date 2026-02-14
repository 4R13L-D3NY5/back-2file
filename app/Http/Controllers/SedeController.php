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
            ->orderBy('id')
            ->get()
            ->map(function ($sede) {
                // Count carreras directly via sede_id column
                $carrerasCount = \App\Models\Carrera::where('sede_id', $sede->id)->count();

                // Count docentes via sede_id on docentes table
                $docentesCount = \App\Models\Docente::where('sede_id', $sede->id)->count();

                return [
                    'id' => $sede->id,
                    'nombre' => $sede->nombre,
                    'codigo' => $sede->codigo,
                    'ciudad' => $sede->ciudad,
                    'activo' => $sede->activo,
                    'carreras_count' => $carrerasCount,
                    'docentes_count' => $docentesCount,
                ];
            });

        // Global Stats
        $globalStats = [
            'total_sedes' => $sedes->count(),
            'sedes_activas' => $sedes->where('activo', true)->count(),
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
