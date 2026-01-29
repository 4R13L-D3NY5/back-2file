<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GrupoController extends Controller
{
    /**
     * Display a listing of subjects with their groups (schedules).
     * Filters by Sede, Carrera, Gestion, Semestre.
     */
    public function index(Request $request)
    {
        $query = Asignatura::query();
        $sedeId = $request->sede_id ?? $request->sede; // Allow alias
        $carreraId = $request->carrera_id ?? $request->carrera;
        $gestion = $request->gestion ?? '1-2026';
        $semestre = $request->semestre;

        // SECURITY: If user is Docente, force filter by their ID
        $user = $request->user();
        // Check role via relationship (lazy load if needed)
        if ($user && $user->rol && $user->rol->codigo === 'DOCENTE' && $user->docente) {
            $query->whereHas('grupos', function ($q) use ($user) {
                $q->where('docente_id', $user->docente->id);
            });
        }

        // 1. Filter by Carrera/Sede via pivot
        if ($carreraId) {
            $query->whereHas('carreras', function ($q) use ($carreraId, $sedeId) {
                $q->where('carreras.id', $carreraId);
                // If sede is provided, ensure the relationship matches that sede too
                if ($sedeId) {
                    $q->where('asignatura_carrera.sede_id', $sedeId);
                }
            });
        } elseif ($sedeId) {
            $query->whereHas('carreras', function ($q) use ($sedeId) {
                $q->where('asignatura_carrera.sede_id', $sedeId);
            });
        }

        // 2. Filter by Semestre (using pivot)
        if ($semestre && $carreraId) {
            $query->whereHas('carreras', function ($q) use ($carreraId, $semestre) {
                $q->where('carreras.id', $carreraId)
                    ->where('asignatura_carrera.semestre', $semestre);
            });
        }

        // 3. Eager Loading
        // Load relationships needed for transformation
        $query->with([
            'carreras' => function ($q) use ($carreraId, $sedeId) {
                // We need to limit eager load to the relevant career/sede to extract correct 'semestre'
                if ($carreraId) $q->where('carreras.id', $carreraId);
                if ($sedeId) $q->where('asignatura_carrera.sede_id', $sedeId);
            },
            'grupos' => function ($q) use ($gestion) {
                $q->where('gestion', $gestion)
                    ->with(['docente', 'horarios.aula.bloque']);
            }
        ]);

        // Pagination
        $materias = $query->paginate($request->per_page ?? 20);

        // Transformation
        $transformed = $materias->getCollection()->map(function ($materia) use ($request) {
            // Determine Context (Carrera/Sede/Semestre)
            // Use the first matched career (since we filtered by it)
            $pivotContext = $materia->carreras->first();

            $carreraNombre = $pivotContext ? $pivotContext->nombre : 'N/A';
            $sedeId = $pivotContext ? $pivotContext->pivot->sede_id : null;
            $semestre = $pivotContext ? $pivotContext->pivot->semestre : null;

            // Resolve Sede Name (Optional optimization: Could eager load Sede in pivot or use a map)
            // For now, let frontend handle ID->Name or assuming context via filter.
            // But Page uses "materia.sede_nombre".
            // Querying Sede name per row is N+1.
            // Let's assume frontend passes Sede name in filters OR we rely on filtered value.
            // Better: Load 'sedes' relation on Carrera? No, pivot has ID.
            // But Asignatura->Carreras (Pivot) -> Sede Relationship?
            // No easy way to get Sede Name without N+1 or join.
            // We'll return "Sede ID" and let frontend resolve it (Stores have the list).

            $gruposList = [];
            foreach ($materia->grupos as $grupo) {
                foreach ($grupo->horarios as $horario) {
                    // Flatten: One item per Schedule Session
                    $gruposList[] = [
                        'grupo' => $grupo->nombre, // '1' or 'GR-1'
                        'tipo_clase' => ucwords(strtolower($grupo->tipo ?? 'TEORICO')), // 'Teorico'
                        'dia' => $horario->dia, // 'LUNES'
                        'hora_inicio' => substr($horario->hora_inicio, 0, 5), // '07:00'
                        'hora_fin' => substr($horario->hora_fin, 0, 5),
                        'docente' => $grupo->docente ? $grupo->docente->nombre_completo : 'Sin Asignar',
                        'aula' => $horario->aula ? $horario->aula->nombre : 'Sin Aula',
                        'bloque' => ($horario->aula && $horario->aula->bloque) ? $horario->aula->bloque->nombre : '-',
                        'capacidad' => $horario->aula ? $horario->aula->capacidad : 0,
                        'pupitres' => $horario->aula ? $horario->aula->pupitres : 0,
                    ];
                }
                // Handle case of group with NO hours (rare but valid)
                if ($grupo->horarios->isEmpty()) {
                    $gruposList[] = [
                        'grupo' => $grupo->nombre,
                        'tipo_clase' => ucwords(strtolower($grupo->tipo ?? 'TEORICO')),
                        'dia' => '-',
                        'hora_inicio' => '-',
                        'hora_fin' => '-',
                        'docente' => $grupo->docente ? $grupo->docente->nombre_completo : 'Sin Asignar',
                        'aula' => '-',
                        'bloque' => '-',
                        'capacidad' => 0,
                        'pupitres' => 0,
                    ];
                }
            }

            return [
                'id' => $materia->id,
                'codigo' => $materia->codigo,
                'nombre' => $materia->nombre,
                'carrera' => $carreraNombre,
                'sede_nombre' => 'Sede ' . $sedeId, // Placeholder
                'semestre' => $semestre,
                'gestion' => $request->gestion,
                'grupos' => $gruposList
            ];
        });

        // Add extra meta for counting (approximation for performance)
        $meta = [
            'current_page' => $materias->currentPage(),
            'last_page' => $materias->lastPage(),
            'total' => $materias->total(),
            'total_materias' => $materias->total(),
            // 'total_grupos' => $query->withCount('grupos')->get()->sum('grupos_count'), // Expensive? Maybe.
            'carrera' => $request->carrera_id ? 'Carrera ID ' . $request->carrera_id : 'Todas',
            'gestion' => $gestion
        ];

        return response()->json([
            'data' => $transformed,
            'meta' => $meta
        ]);
    }
    public function show($id)
    {
        $grupo = \App\Models\Grupo::with([
            'asignatura.carreras.sedes', // To find context
            'asignatura.carreras.director',
            'docente.sede',
            'horarios.aula.bloque'
        ])->findOrFail($id);

        $asignatura = $grupo->asignatura;
        $docente = $grupo->docente;

        // Context Logic: Try to find the specific Carrera/Sede context for this group if possible
        // Since a group belongs to an Asignatura, and Asignatura belongs to many Carreras,
        // we might just pick the first one or need extra params.
        // For the Cover, we usually want the Carrera that the group is assigned to.
        // But Grupo is linked to Asignatura directly.
        // Let's assume the first Carrera of the Asignatura for now, or use pivot if we had it on Grupo.
        // Actually, 'asignatura_carrera' pivot has 'semestre' and 'sede_id'.

        $carreraPivot = $asignatura->carreras->first();
        $carrera = $carreraPivot;
        $area = $carrera ? $carrera->area : 'ÁREA NO DEFINIDA';
        $semestre = $carreraPivot ? $carreraPivot->pivot->semestre : 'N/A';
        $sedeId = $carreraPivot ? $carreraPivot->pivot->sede_id : null;
        $sede = $sedeId ? \App\Models\Sede::find($sedeId)->nombre : 'SEDE NO DEFINIDA';

        return response()->json([
            'area' => $area,
            'carrera' => $carrera ? $carrera->nombre : 'Sin Carrera',
            'sede' => $sede,
            'docente_nombre' => $docente ? $docente->nombre_completo : 'Sin Docente',
            'asignatura' => $asignatura->nombre,
            'codigo_asignatura' => $asignatura->codigo,
            'semestre' => $semestre,
            'grupo' => $grupo->nombre,
            'gestion' => $grupo->gestion
        ]);
    }
}
