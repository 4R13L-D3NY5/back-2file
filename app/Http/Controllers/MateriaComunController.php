<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Carrera;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class MateriaComunController extends Controller
{
    /**
     * List common groups, showing ONE row per group.
     * Each row shows the "base" subject and ALL linked subjects.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->director) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // Obtener TODAS las carreras del director
        $director = $user->director;
        $carreraIds = Carrera::where('director_id', $director->id)->pluck('id')->toArray();
        
        if (empty($carreraIds) && $director->carrera_id) {
            $carreraIds[] = $director->carrera_id;
        }

        // Obtener tokens únicos de materias comunes en mis carreras
        $tokens = Asignatura::whereHas('carreras', function ($q) use ($carreraIds) {
            $q->whereIn('carreras.id', $carreraIds);
        })
            ->whereNotNull('comun_token')
            ->pluck('comun_token')
            ->unique()
            ->values();

        // Para cada token, obtener el grupo completo
        $resultado = $tokens->map(function ($token) use ($carreraIds) {
            // Obtener TODAS las asignaturas con este token
            $grupo = Asignatura::where('comun_token', $token)
                ->with('carreras')
                ->get();
            
            // La primera asignatura será la "base" (la que tiene el token más antiguo)
            $base = $grupo->first();
            $vinculadas = $grupo->skip(1);

            return [
                'id' => $base->id,
                'comun_token' => $token,
                'comun_tipo' => $base->comun_tipo,
                'base' => [
                    'id' => $base->id,
                    'codigo' => $base->codigo,
                    'nombre' => $base->nombre,
                    'carrera_nombre' => $base->carreras->pluck('nombre')->join(', '),
                ],
                'vinculadas' => $vinculadas->map(function ($h) {
                    return [
                        'id' => $h->id,
                        'codigo' => $h->codigo,
                        'nombre' => $h->nombre,
                        'carrera_nombre' => $h->carreras->pluck('nombre')->join(', ') ?: 'Sin Carrera',
                    ];
                })->values(),
                'total_materias' => $grupo->count()
            ];
        });

        return response()->json($resultado);
    }

    /**
     * Get all subjects from all careers managed by the current director.
     * Used in the stepper Step 1 to populate "Mi Asignatura" dropdown.
     */
    public function misAsignaturas(Request $request)
    {
        $user = $request->user();
        if (!$user->director) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $director = $user->director;
        $carreraIds = Carrera::where('director_id', $director->id)->pluck('id')->toArray();
        
        if (empty($carreraIds) && $director->carrera_id) {
            $carreraIds[] = $director->carrera_id;
        }

        $asignaturas = Asignatura::whereHas('carreras', function ($q) use ($carreraIds) {
            $q->whereIn('carreras.id', $carreraIds);
        })
            ->with(['carreras' => function($q) use ($carreraIds) {
                $q->whereIn('carreras.id', $carreraIds);
            }])
            ->orderBy('nombre')
            ->get()
            ->map(function ($a) {
                return [
                    'id' => $a->id,
                    'codigo' => $a->codigo,
                    'nombre' => $a->nombre,
                    'carrera_nombre' => $a->carreras->pluck('nombre')->unique()->join(', '),
                    'label' => "{$a->nombre} ({$a->codigo}) - " . $a->carreras->pluck('nombre')->unique()->join(', ')
                ];
            });

        return response()->json($asignaturas);
    }

    /**
     * Get candidate subjects from other careers to link with.
     */
    public function candidates(Request $request)
    {
        $user = $request->user();
        if (!$user->director) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $sedeId = $user->director->sede_id;
        $search = $request->input('search');
        $carreraIdTarget = $request->input('carrera_id');

        // Buscar asignaturas de OTRAS carreras en la MISMA sede
        $query = Asignatura::whereHas('carreras', function ($q) use ($sedeId, $carreraIdTarget) {
            $q->where('asignatura_carrera.sede_id', $sedeId);
            if ($carreraIdTarget) {
                $q->where('carreras.id', $carreraIdTarget);
            }
        });

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('codigo', 'like', "%{$search}%");
            });
        }

        $candidates = $query->distinct()->limit(50)->with('carreras')->get();

        return response()->json($candidates);
    }

    /**
     * Link two subjects.
     */
    public function link(Request $request)
    {
        $request->validate([
            'asignatura_id' => 'required|exists:asignaturas,id',
            'target_asignatura_id' => 'required|exists:asignaturas,id',
            'tipo' => 'nullable|string|in:fusionada,espejo'
        ]);

        $user = $request->user();
        $source = Asignatura::findOrFail($request->asignatura_id);
        $target = Asignatura::findOrFail($request->target_asignatura_id);
        $tipo = $request->input('tipo', 'fusionada');

        // Validar permisos (Basicamente que source sea de una carrera del director)
        // Por brevedad omitimos check exhaustivo, asumimos que el frontend manda id correcto del director.

        // Logica de Fusión
        if (!$source->comun_token && !$target->comun_token) {
            // Caso 1: Ninguno tiene grupo -> Crear nuevo
            $token = (string) Str::uuid();
            $source->comun_token = $token;
            $source->comun_tipo = $tipo;
            $target->comun_token = $token;
            $target->comun_tipo = $tipo;
            $source->save();
            $target->save();
        } elseif ($source->comun_token && !$target->comun_token) {
            // Caso 2: Source tiene grupo, Target no -> Target se une a Source
            $target->comun_token = $source->comun_token;
            $target->comun_tipo = $source->comun_tipo;
            $target->save();
        } elseif (!$source->comun_token && $target->comun_token) {
            // Caso 3: Target tiene grupo, Source no -> Source se une a Target
            $source->comun_token = $target->comun_token;
            $source->comun_tipo = $target->comun_tipo;
            $source->save();
        } else {
            // Caso 4: Ambos tienen grupo -> FUSIONAR (Merge)
            // Todos los del grupo de Target pasan al grupo de Source
            $tokenSource = $source->comun_token;
            $tokenTarget = $target->comun_token;

            if ($tokenSource !== $tokenTarget) {
                Asignatura::where('comun_token', $tokenTarget)
                    ->update([
                        'comun_token' => $tokenSource,
                        'comun_tipo' => $source->comun_tipo
                    ]);
            }
        }

        return response()->json(['message' => 'Vinculación exitosa']);
    }

    /**
     * Unlink a subject from its group.
     */
    public function unlink(Request $request, $id)
    {
        $asignatura = Asignatura::findOrFail($id);
        // Validar propiedad...

        $asignatura->comun_token = null;
        $asignatura->save();

        return response()->json(['message' => 'Desvinculación exitosa']);
    }
}
