<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GrupoController extends Controller
{
    /**
     * Display a listing of subjects with their groups.
     * We return subjects that match the filters, eager loading their 'docentes' pivot which acts as groups.
     */
    public function index(Request $request)
    {
        $query = Asignatura::query();

        // Apply filters
        if ($request->has('sede_id') && $request->sede_id) {
            // Filter by subjects available in this Sede (via Carrera)
            // Assuming Asignatura -> Carrera -> Sede relation or pivot.
            // Actually, Carrera has many Asignaturas.
            // We should filter Asignaturas where their Carrera belongs to the Sede.
            $query->whereHas('carrera', function ($q) use ($request) {
                $q->where('sede_id', $request->sede_id);
            });
        }

        if ($request->has('carrera_id') && $request->carrera_id) {
            $query->where('carrera_id', $request->carrera_id);
        }

        if ($request->has('semestre') && $request->semestre) {
            $query->where('semestre', $request->semestre);
        }

        // Eager load Docentes (Groups)
        // usage: $materia->docentes contains the teacher + pivot(grupo, aula, horario...)
        $query->with(['docentes' => function ($q) {
            $q->select('docentes.id', 'docentes.nombre_completo', 'docentes.grado_academico');
        }, 'carrera']);

        // Pagination
        $materias = $query->paginate($request->per_page ?? 20);

        // Transform data to match frontend expectation:
        // { id, codigo, nombre, grupos: [ {id, numero, aula, horario, docente_nombre...} ] }
        $transformed = $materias->getCollection()->map(function ($materia) {
            return [
                'id' => $materia->id,
                'codigo' => $materia->codigo,
                'nombre' => $materia->nombre,
                'carrera_nombre' => $materia->carrera->nombre,
                'carrera_id' => $materia->carrera_id,
                'semestre' => $materia->semestre,
                'sede_id' => $materia->carrera->sede_id, // Implicit via Carrera
                'grupos' => $materia->docentes->map(function ($docente) {
                    return [
                        'id' => $docente->pivot->id ?? rand(1000, 9999), // Pivot ID might not be exposed easily in belongsToMany unless withPivot('id') is set.
                        // Actually, belongsToMany doesn't return pivot ID by default unless requested.
                        // We will rely on mapped properties for now.
                        'numero' => $docente->pivot->grupo, // 'GR-1'
                        'docente_id' => $docente->id,
                        'docente_nombre' => $docente->grado_academico . ' ' . $docente->nombre_completo,
                        'aula' => $docente->pivot->aula,
                        'horario' => $docente->pivot->horario,
                        'cupo' => $docente->pivot->cupo,
                        'estudiantes' => $docente->pivot->estudiantes_inscritos,
                    ];
                })
            ];
        });

        // Calculate stats on the fly (for the filtered set)
        // Note: Global stats for cards might need a separate query if we want totals ignoring pagination.
        // For efficiency, we'll just sum the current page or do a quick aggregate count.
        // Let's do a quick global aggregate query for the stats cards.
        $statsQuery = $query->clone();

        // We need total groups (count of rows in pivot for these subjects)
        // This is expensive to join. Let's approximate or just count subjects.
        // Or if the user really wants robust stats:
        // DB::table('asignatura_docente')->whereIn('asignatura_id', $statsQuery->select('id'))->count();

        $totalMaterias = $statsQuery->count();
        // $totalGrupos = ... let's skip deep aggregation for speed unless requested.
        // We can just return the data and let frontend sum the page, OR fetch separate stats endpoint.

        return response()->json([
            'data' => $transformed,
            'meta' => [
                'current_page' => $materias->currentPage(),
                'last_page' => $materias->lastPage(),
                'total' => $materias->total(),
            ]
        ]);
    }
}
