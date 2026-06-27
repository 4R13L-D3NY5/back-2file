<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CampusController extends Controller
{
    public function index()
    {
        $campus = Campus::with(['sede', 'carreras'])->get()->map(function ($camp) {
            return [
                'id' => $camp->id,
                'nombre' => $camp->nombre,
                'sede_id' => $camp->sede_id,
                'sede' => $camp->sede ? $camp->sede->nombre : null,
                'direccion' => $camp->direccion,
                'activo' => (bool)$camp->activo,
                'carreras' => $camp->carreras->count(),
                'lista_carreras' => $camp->carreras->map(function($c) {
                    return [
                        'id' => $c->id,
                        'nombre' => $c->nombre
                    ];
                })
            ];
        });

        return response()->json($campus);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'sede_id' => 'required|exists:sedes,id',
            'direccion' => 'nullable|string|max:255',
            'activo' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation errors', 'errors' => $validator->errors()], 422);
        }

        $campus = Campus::create($request->all());
        $campus->load('sede');

        return response()->json([
            'id' => $campus->id,
            'nombre' => $campus->nombre,
            'sede_id' => $campus->sede_id,
            'sede' => $campus->sede ? $campus->sede->nombre : null,
            'direccion' => $campus->direccion,
            'activo' => (bool)$campus->activo,
            'carreras' => 0,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $campus = Campus::find($id);
        if (!$campus) {
            return response()->json(['message' => 'Campus no encontrado'], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'sometimes|required|string|max:255',
            'sede_id' => 'sometimes|required|exists:sedes,id',
            'direccion' => 'nullable|string|max:255',
            'activo' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation errors', 'errors' => $validator->errors()], 422);
        }

        $data = $request->all();
        if(!isset($data['activo'])) {
             // In case toggle sends just activo without validation
        }
        $campus->update($data);
        $campus->load('sede');

        return response()->json([
            'id' => $campus->id,
            'nombre' => $campus->nombre,
            'sede_id' => $campus->sede_id,
            'sede' => $campus->sede ? $campus->sede->nombre : null,
            'direccion' => $campus->direccion,
            'activo' => (bool)$campus->activo,
            'carreras' => 0,
        ]);
    }

    public function destroy($id)
    {
        $campus = Campus::find($id);
        if (!$campus) {
            return response()->json(['message' => 'Campus no encontrado'], 404);
        }

        $campus->delete();
        return response()->json(['message' => 'Campus eliminado']);
    }

    public function obtenerCarreras($id)
    {
        $campus = Campus::with('carreras')->find($id);
        if (!$campus) {
            return response()->json(['message' => 'Campus no encontrado'], 404);
        }

        $carreras = $campus->carreras->map(function ($c) {
            return [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'sigla' => $c->sigla
            ];
        });

        return response()->json($carreras);
    }

    public function asignarCarreras(Request $request, $id)
    {
        $campus = Campus::find($id);
        if (!$campus) {
            return response()->json(['message' => 'Campus no encontrado'], 404);
        }

        $validator = Validator::make($request->all(), [
            'carreras' => 'required|array',
            'carreras.*' => 'exists:carreras,id'
        ]);

        \Illuminate\Support\Facades\Log::info("Asignando carreras a campus ID: {$id}", ['payload' => $request->all()]);

        if ($validator->fails()) {
            \Illuminate\Support\Facades\Log::error("Validación fallida al asignar carreras", ['errors' => $validator->errors()]);
            return response()->json(['message' => 'Validation errors', 'errors' => $validator->errors()], 422);
        }

        $campus->carreras()->syncWithoutDetaching($request->carreras);
        
        \Illuminate\Support\Facades\Log::info("Carreras asignadas correctamente a campus ID: {$id}");

        return response()->json(['message' => 'Carreras asignadas correctamente']);
    }

    public function deasignarCarrera($campusId, $carreraId)
    {
        $campus = Campus::find($campusId);
        if ($campus) {
            $campus->carreras()->detach($carreraId);
            return response()->json(['message' => 'Carrera desasignada correctamente']);
        }
        return response()->json(['message' => 'Campus no encontrado'], 404);
    }

    public function obtenerEvaluadores()
    {
        $evaluadores = User::with(['campus.carreras', 'campusAsignados.carreras'])
            ->whereIn('rol_id', [7, 9])
            ->where(function ($query) {
                $query->whereNotNull('campus_id')
                    ->orWhereHas('campusAsignados')
                    ->orWhere('rol_id', 9);
            })
            ->get()
            ->map(function ($usuario) {
                return $this->mapearEvaluador($usuario);
            });

        return response()->json($evaluadores);
    }

    public function evaluadoresDisponibles()
    {
        $disponibles = User::where('rol_id', 7)
            ->whereNull('campus_id')
            ->doesntHave('campusAsignados')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'nombre' => trim($u->nombre . ' ' . $u->apellido),
                    'email' => $u->email,
                ];
            });

        return response()->json($disponibles);
    }

    public function asignarEvaluador(Request $request, $id)
    {
        $rolId = (int) $request->input('rol_id', 7);
        $campusIds = $rolId === 9 ? [] : $this->normalizarCampusIds($request, $id);

        if ($rolId !== 9 && count($campusIds) === 0) {
            return response()->json(['message' => 'Debe seleccionar al menos un campus'], 422);
        }

        if ($rolId !== 9 && Campus::whereIn('id', $campusIds)->count() !== count($campusIds)) {
            return response()->json(['message' => 'Uno o mas campus seleccionados no existen'], 422);
        }

        $rules = [
            'rol_id' => 'nullable|exists:roles,id',
            'campus_id' => 'nullable|integer|exists:campus,id',
            'campus_ids' => 'nullable|array',
            'campus_ids.*' => 'integer|exists:campus,id',
        ];

        if ($request->boolean('crear_nuevo')) {
            $validator = Validator::make($request->all(), array_merge($rules, [
                'nombre' => 'required|string|max:255',
                'apellido' => 'required|string|max:255',
                'ci' => 'required|string|unique:users,ci',
                'email' => 'required|email|unique:users,email',
            ]));

            if ($validator->fails()) {
                return response()->json(['message' => 'Errores de validacion', 'errors' => $validator->errors()], 422);
            }

            $usuario = DB::transaction(function () use ($request, $rolId, $campusIds) {
                $usuario = new User();
                $usuario->nombre = $request->nombre;
                $usuario->apellido = $request->apellido;
                $usuario->email = $request->email;
                $usuario->username = $request->ci;
                $usuario->ci = $request->ci;
                $usuario->telefono = $request->telefono ?? null;
                $usuario->password = bcrypt((string) $request->ci);
                $usuario->password_change_required = true;
                $usuario->rol_id = $rolId;
                $usuario->estado = true;
                $usuario->campus_id = $campusIds[0] ?? null;
                $usuario->save();
                $usuario->campusAsignados()->sync($campusIds);

                return $usuario;
            });

            $rolNombre = $usuario->rol_id == 9 ? 'Responsable de Evaluaciones' : 'Evaluador';
            return response()->json(['message' => "{$rolNombre} creado y asignado correctamente"]);
        }

        $validator = Validator::make($request->all(), array_merge($rules, [
            'usuario_id' => 'required|exists:users,id',
        ]));

        if ($validator->fails()) {
            return response()->json(['message' => 'Errores de validacion', 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $rolId, $campusIds) {
            $usuario = User::findOrFail($request->usuario_id);
            $usuario->rol_id = $rolId;
            $usuario->campus_id = $campusIds[0] ?? null;
            $usuario->save();
            $usuario->campusAsignados()->sync($campusIds);
        });

        return response()->json(['message' => 'Usuario asignado correctamente']);
    }

    public function removerEvaluador($campusId, $userId)
    {
        $usuario = User::with('campusAsignados')->find($userId);
        if (!$usuario) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $campusId = (int) $campusId;
        $perteneceAlCampus = (int) $usuario->campus_id === $campusId
            || $usuario->campusAsignados->contains('id', $campusId);

        if (!$perteneceAlCampus) {
            return response()->json(['message' => 'Usuario no pertenece a este campus'], 404);
        }

        $usuario->campusAsignados()->detach($campusId);

        if ((int) $usuario->campus_id === $campusId) {
            $usuario->campus_id = $usuario->campusAsignados()->pluck('campus.id')->first();
            $usuario->save();
        }

        return response()->json(['message' => 'Evaluador removido correctamente']);
    }

    private function normalizarCampusIds(Request $request, $fallbackCampusId)
    {
        $ids = collect($request->input('campus_ids', []));

        if ($ids->isEmpty() && $request->filled('campus_id')) {
            $ids = collect([$request->input('campus_id')]);
        }

        if ($ids->isEmpty() && $fallbackCampusId) {
            $ids = collect([$fallbackCampusId]);
        }

        return $ids
            ->map(function ($id) {
                return (int) $id;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function mapearEvaluador(User $usuario)
    {
        $campusAsignados = $this->campusAsignadosDeUsuario($usuario);
        $campusPrincipal = $campusAsignados->first();
        $carreras = $campusAsignados
            ->flatMap(function ($campus) {
                return $campus->carreras->pluck('nombre');
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'id' => $usuario->id,
            'nombre' => trim($usuario->nombre . ' ' . $usuario->apellido),
            'email' => $usuario->email,
            'estado' => (bool) $usuario->estado,
            'rol_id' => $usuario->rol_id,
            'campus_id' => $campusPrincipal ? $campusPrincipal->id : null,
            'campus' => $campusPrincipal ? $campusPrincipal->nombre : 'Sin Campus',
            'campus_ids' => $campusAsignados->pluck('id')->values()->all(),
            'campus_asignados' => $campusAsignados
                ->map(function ($campus) {
                    return [
                        'id' => $campus->id,
                        'nombre' => $campus->nombre,
                        'sede_id' => $campus->sede_id,
                    ];
                })
                ->values()
                ->all(),
            'carreras' => $carreras,
        ];
    }

    private function campusAsignadosDeUsuario(User $usuario)
    {
        $campusAsignados = $usuario->campusAsignados;

        if ($campusAsignados->isEmpty() && $usuario->campus) {
            $campusAsignados = collect([$usuario->campus]);
        }

        return $campusAsignados->unique('id')->values();
    }
}
