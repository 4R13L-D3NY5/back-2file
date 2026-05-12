<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use Illuminate\Http\Request;
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
        // rol_id 7 = EVALUACIONES
        $evaluadores = \App\Models\User::with(['campus.carreras'])
            ->where('rol_id', 7)
            ->whereNotNull('campus_id')
            ->get()
            ->map(function ($u) {
                $carreras = $u->campus ? $u->campus->carreras->pluck('nombre')->toArray() : [];
                return [
                    'id' => $u->id,
                    'nombre' => $u->nombre . ' ' . $u->apellido,
                    'email' => $u->email,
                    'estado' => (bool) $u->estado,
                    'campus_id' => $u->campus_id,
                    'campus' => $u->campus ? $u->campus->nombre : 'Sin Campus',
                    'carreras' => $carreras
                ];
            });

        return response()->json($evaluadores);
    }

    public function evaluadoresDisponibles()
    {
        // Evaluadores que aún no están asignados a ningún campus
        $disponibles = \App\Models\User::where('rol_id', 7)
            ->whereNull('campus_id')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'nombre' => $u->nombre . ' ' . $u->apellido,
                    'email' => $u->email,
                ];
            });

        return response()->json($disponibles);
    }

    public function asignarEvaluador(Request $request, $id)
    {
        $campus = null;
        if ($request->rol_id != 9) {
            $campus = Campus::find($id);
            if (!$campus) {
                return response()->json(['message' => 'Campus no encontrado'], 404);
            }
        }

        if ($request->has('crear_nuevo') && $request->crear_nuevo) {
            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:255',
                'apellido' => 'required|string|max:255',
                'ci' => 'required|string|unique:users,ci',
                'email' => 'required|email|unique:users,email' // Unique email check
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Errores de validación', 'errors' => $validator->errors()], 422);
            }

            $usuario = new \App\Models\User();
            $usuario->nombre = $request->nombre;
            $usuario->apellido = $request->apellido;
            $usuario->email = $request->email;
            $usuario->username = $request->ci;
            $usuario->ci = $request->ci;
            $usuario->telefono = $request->telefono ?? null;
            $usuario->password = bcrypt((string)$request->ci);
            $usuario->password_change_required = true;
            $usuario->rol_id = $request->rol_id ?? 7;
            $usuario->estado = true;
            $usuario->campus_id = $campus ? $campus->id : null;
            $usuario->save();

            $rolNombre = $usuario->rol_id == 9 ? 'Responsable de Evaluaciones' : 'Evaluador';
            return response()->json(['message' => "{$rolNombre} creado y asignado al campus exitosamente"]);
        } else {
            $validator = Validator::make($request->all(), [
                'usuario_id' => 'required|exists:users,id',
                'rol_id' => 'nullable|exists:roles,id'
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Errores de validación', 'errors' => $validator->errors()], 422);
            }

            $usuario = \App\Models\User::find($request->usuario_id);
            
            // Si viene un rol_id en la petición, actualizarlo (por si se quiere promover a Responsable)
            if ($request->has('rol_id')) {
                $usuario->rol_id = $request->rol_id;
            }

            $usuario->campus_id = $campus ? $campus->id : null;
            $usuario->save();

            return response()->json(['message' => 'Usuario asignado correctamente']);
        }
    }

    public function removerEvaluador($campusId, $userId)
    {
        $usuario = \App\Models\User::where('id', $userId)->where('campus_id', $campusId)->first();
        if ($usuario) {
            $usuario->campus_id = null;
            $usuario->save();
            return response()->json(['message' => 'Evaluador removido correctamente']);
        }
        return response()->json(['message' => 'Usuario no encontrado o no pertenece a este campus'], 404);
    }
}
