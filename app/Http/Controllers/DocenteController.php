<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use Illuminate\Http\Request;

class DocenteController extends Controller
{
    public function index(Request $request)
    {
        $query = Docente::query()->with(['grupos.asignatura.carreras.sedes', 'grupos.horarios']);

        // Search
        if ($request->has('q') && $request->q) {
            $term = $request->q;
            $query->where(function ($q) use ($term) {
                $q->where('nombre_completo', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('ci', 'like', "%{$term}%");
            });
        } elseif ($request->has('search') && $request->search) {
            // Fallback for legacy calls
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('nombre_completo', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        // Filter: Sede
        if ($request->has('sede_id') && $request->sede_id) {
            $sedeId = $request->sede_id;
            // Filter docentes who have groups in asignaturas belonging to carreras in the selected sede
            $query->whereHas('grupos.asignatura.carreras.sedes', function ($q) use ($sedeId) {
                $q->where('sedes.id', $sedeId);
            });
        }

        // Filter: Estado
        if ($request->has('estado') && $request->estado !== null && $request->estado !== 'null') {
            $isActive = filter_var($request->estado, FILTER_VALIDATE_BOOLEAN);
            $query->where('estado', $isActive);
        }

        $docentes = $query->orderBy('nombre_completo')->get();

        $data = $docentes->map(function ($docente) {
            // Calcular estadísticas basades en Grupos
            $grupos = $docente->grupos;
            $materiasIds = $grupos->pluck('asignatura_id')->unique();

            // Inferencia de Sede (Tomar del primer grupo)
            $sede = null;
            $firstGrupo = $grupos->first();
            if ($firstGrupo && $firstGrupo->asignatura) {
                // Try via pivot first (most accurate)
                $carrera = $firstGrupo->asignatura->carreras->first();
                if ($carrera) {
                    // We need Sede name. Use 'sedes' relationship (Many-to-Many)
                    $firstSede = $carrera->sedes->first();
                    if ($firstSede) {
                        $sede = $firstSede;
                    } elseif ($carrera->sede) {
                        $sede = $carrera->sede;
                    }
                }
            }

            // Merge stats into full model array
            $mapped = $docente->toArray();

            // Override or append calculated fields
            $mapped['materias_count'] = $materiasIds->count();
            $mapped['grupos_count'] = $grupos->count();
            $mapped['horas_semanales'] = 0; // TODO: Calcular real

            // Inferred Sede Override
            if (empty($mapped['sede']) && $sede) {
                $mapped['sede'] = ['nombre' => $sede->nombre];
                $mapped['sede_id'] = $sede->id;
                $mapped['sede_nombre'] = $sede->nombre; // Helper
            }

            return $mapped;
        });

        // Calculate Stats based on the filtered result
        $stats = [
            'total_docentes' => $data->count(),
            'activos' => $data->where('estado', true)->count(),
            'total_materias' => $data->sum('materias_count'),
            'total_grupos' => $data->sum('grupos_count')
        ];

        return response()->json([
            'data' => $data,
            'stats' => $stats
        ]);
    }
}
