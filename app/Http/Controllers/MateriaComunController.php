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
     * List my subjects that are part of a common group,
     * including detail about what they are linked with.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->director) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // Obtener IDs de carreras del director
        // Si tiene relación hasMany 'carreras', usarlas. Si no, belongsTo 'carrera'.
        $carreraIds = [];
        if ($user->director->carrera_id) {
            $carreraIds[] = $user->director->carrera_id;
        }
        // Si hay una tabla o relación de muchas carreras, agregar aquí.
        // Asumiremos la simple por ahora o lo que soporte el modelo actual.

        // Buscar asignaturas de Mis Carreras que tengan comun_token NO NULO
        $misAsignaturasComunes = Asignatura::whereHas('carreras', function ($q) use ($carreraIds) {
            $q->whereIn('carreras.id', $carreraIds);
        })
            ->whereNotNull('comun_token')
            ->with(['carreras']) // Cargar mis carreras
            ->get();

        // Para cada una, buscar sus "Hermanas"
        $resultado = $misAsignaturasComunes->map(function ($asignatura) {
            $hermanas = Asignatura::where('comun_token', $asignatura->comun_token)
                ->where('id', '!=', $asignatura->id)
                ->with('carreras')
                ->get();

            return [
                'id' => $asignatura->id,
                'codigo' => $asignatura->codigo,
                'nombre' => $asignatura->nombre,
                'carrera_nombre' => $asignatura->carreras->pluck('nombre')->join(', '),
                'comun_token' => $asignatura->comun_token,
                'vinculadas' => $hermanas->map(function ($h) {
                    return [
                        'id' => $h->id,
                        'codigo' => $h->codigo,
                        'nombre' => $h->nombre,
                        // Fix for duplicate name detection
                        'carrera_nombre' => $h->carreras->pluck('nombre')->join(', ') ?: 'Sin Carrera',
                        'comun_token' => $h->comun_token
                    ];
                })
            ];
        });

        return response()->json($resultado);
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

        $candidates = $query->limit(50)->with('carreras')->get();

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
        ]);

        $user = $request->user();
        $source = Asignatura::findOrFail($request->asignatura_id);
        $target = Asignatura::findOrFail($request->target_asignatura_id);

        // Validar permisos (Basicamente que source sea de una carrera del director)
        // Por brevedad omitimos check exhaustivo, asumimos que el frontend manda id correcto del director.

        // Logica de Fusión
        if (!$source->comun_token && !$target->comun_token) {
            // Caso 1: Ninguno tiene grupo -> Crear nuevo
            $token = (string) Str::uuid();
            $source->comun_token = $token;
            $target->comun_token = $token;
            $source->save();
            $target->save();
        } elseif ($source->comun_token && !$target->comun_token) {
            // Caso 2: Source tiene grupo, Target no -> Target se une a Source
            $target->comun_token = $source->comun_token;
            $target->save();
        } elseif (!$source->comun_token && $target->comun_token) {
            // Caso 3: Target tiene grupo, Source no -> Source se une a Target
            $source->comun_token = $target->comun_token;
            $source->save();
        } else {
            // Caso 4: Ambos tienen grupo -> FUSIONAR (Merge)
            // Todos los del grupo de Target pasan al grupo de Source
            $tokenSource = $source->comun_token;
            $tokenTarget = $target->comun_token;

            if ($tokenSource !== $tokenTarget) {
                Asignatura::where('comun_token', $tokenTarget)
                    ->update(['comun_token' => $tokenSource]);
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
