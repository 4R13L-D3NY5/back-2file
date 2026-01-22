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
                // Contar docentes reales a través de grupos
                // Optimizacion: Usar Join directo para evitar problemas con whereHas anidado en SoftDeletes/Nulls
                $docentesCount = Grupo::join('asignaturas', 'grupos.asignatura_id', '=', 'asignaturas.id')
                    ->join('asignatura_carrera', 'asignaturas.id', '=', 'asignatura_carrera.asignatura_id') // Correct join sequence
                    ->where('asignatura_carrera.sede_id', $sede->id)
                    ->whereNull('grupos.deleted_at')
                    ->whereNull('asignaturas.deleted_at')
                    ->distinct('grupos.docente_id')
                    ->count('grupos.docente_id');

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

        return response()->json($sedes);
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
