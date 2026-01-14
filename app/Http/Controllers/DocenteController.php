<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Docente;

class DocenteController extends Controller
{
    public function index(Request $request)
    {
        $query = Docente::with(['sede', 'user', 'asignaturas']);

        // Filters
        if ($request->has('sede_id') && $request->sede_id) {
            $query->where('sede_id', $request->sede_id);
        }

        if ($request->has('estado')) {
            $estado = $request->estado === 'true' || $request->estado === 1 || $request->estado === '1';
            $query->where('estado', $estado);
        }

        if ($request->has('q')) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('nombre_completo', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('especialidad', 'like', "%{$search}%");
            });
        }

        $paginated = $query->latest()->paginate(20);

        // Transform to add "grupos" logic (Now READ from Pivot Table)
        $paginated->getCollection()->transform(function ($docente) {
            $grupos = [];
            foreach ($docente->asignaturas as $asignatura) {
                // Read from Pivot
                if ($asignatura->pivot && $asignatura->pivot->grupo) {
                    $grupos[] = $asignatura->pivot->grupo;
                }
            }
            $docente->grupos_simulados = array_values(array_unique($grupos));
            return $docente;
        });

        // Calculate Global Stats (Respecting current filters)
        // Clone query to avoid paginator limitations
        $statsQuery = $query->clone();

        $stats = [
            'total_docentes' => $statsQuery->count(),
            'activos' => $statsQuery->clone()->where('estado', true)->count(),
            // For calculating assigned subjects and groups, we need a bit more cost,
            // but for 800 teachers it is acceptable.
            // Using sum of relationships count.
            'total_materias' => $statsQuery->clone()->withCount('asignaturas')->get()->sum('asignaturas_count'),
            // Groups is trickier as it's in pivot, let's approximate or count direct pivot rows if possible,
            // or just sum the relationship count (since 1 relationship row = 1 group assignment).
            'total_grupos' => $statsQuery->clone()->withCount('asignaturas')->get()->sum('asignaturas_count') // Each row in pivot is a group assignment
        ];

        return response()->json([
            'data' => $paginated->items(),
            'links' => $paginated->linkCollection(), // Compatible fields
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'stats' => $stats
        ]);
    }
}
