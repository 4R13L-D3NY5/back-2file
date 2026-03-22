<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Director;
use App\Models\Carrera;
use App\Observers\DirectorObserver;
use App\Observers\CarreraObserver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with([
            'rol',
            'director.carrera',
            'director.carreras',
            'director.sede',
            'docente.sede',
            'sede'
        ])->orderBy('id', 'desc');

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('nombre', 'like', "%{$search}%")
                    ->orWhere('apellido', 'like', "%{$search}%")
                    ->orWhere('ci', 'like', "%{$search}%");
            });
        }

        $users = $query->get();

        // Transformar datos para frontend (Optimizado)
        $users->transform(function ($user) {
            // Resolver Sede
            $sedeNombre = null;
            if ($user->sede) {
                $sedeNombre = $user->sede->nombre;
            } elseif ($user->docente && $user->docente->sede) {
                $sedeNombre = $user->docente->sede->nombre;
            } elseif ($user->director && $user->director->sede) {
                $sedeNombre = $user->director->sede->nombre;
            }

            // Resolver Carrera
            $carreraNombre = null;

            // 1. Si es Director, ver perfil
            if ($user->director) {
                if ($user->director->carrera) {
                    $carreraNombre = $user->director->carrera->nombre;
                } elseif ($user->director->carreras->isNotEmpty()) {
                    $carreraNombre = $user->director->carreras->pluck('nombre')->implode(', ');
                }
            }

            // 2. Si falló o no es director, intentar parsear la columna 'carrera' (legacy/string ids o texto)
            if (!$carreraNombre && $user->carrera) {
                // Si parece ser una lista de IDs (ej: "14, 15")
                if (preg_match('/^[\d,\s]+$/', $user->carrera)) {
                    $ids = array_map('trim', explode(',', $user->carrera));
                    // Aquí todavía podríamos tener un pequeño N+1 si no sabemos qué carreras son,
                    // pero al menos limitamos a los IDs del campo legacy.
                    // Optimización: Cache de nombres de carrera para este request si fuera muy pesado.
                    $names = \App\Models\Carrera::whereIn('id', $ids)->pluck('nombre')->toArray();
                    $carreraNombre = !empty($names) ? implode(', ', $names) : $user->carrera;
                } else {
                    $carreraNombre = $user->carrera; // Es un texto literal
                }
            }

            $user->setAttribute('carrera_nombre', $carreraNombre);
            $user->setAttribute('sede_nombre', $sedeNombre);
            return $user;
        });

        return response()->json($users);
    }

    public function store(Request $request)
    {
        // Validar campos extendidos
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'apellido' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'ci' => 'required|string|max:20', // No unique global si hay duplicados
            'telefono' => 'nullable|string|max:20',
            'rol_id' => 'required|exists:roles,id',
            'carrera' => 'nullable|string|max:255',
            'sede_id' => 'nullable|exists:sedes,id',
            'estado' => 'sometimes|string|in:activo,inactivo,true,false,1,0'
        ]);
        
        // Convertir estado a booleano
        if (isset($validated['estado'])) {
            $estado = $validated['estado'];
            if (in_array($estado, ['activo', 'true', '1'], true)) {
                $validated['estado'] = true;
            } elseif (in_array($estado, ['inactivo', 'false', '0'], true)) {
                $validated['estado'] = false;
            } else {
                $validated['estado'] = true; // default
            }
        } else {
            $validated['estado'] = true; // default si no se envía
        }

        // Generar username automaticamente si no viene, e.g. nombre.apellido
        if (!$request->has('username')) {
            $baseUsername = Str::slug($validated['nombre'] . '.' . $validated['apellido']);
            $validated['username'] = $this->generateUniqueUsername($baseUsername);
        } else {
            $request->validate(['username' => 'required|string|unique:users,username']);
            $validated['username'] = $request->username;
        }

        // Password default 'password' if not set, else hash it
        // Password default es el CI
        $validated['password'] = Hash::make($validated['ci']);
        $validated['password_change_required'] = false;

        $user = User::create($validated);
        $user->load('rol');

        // Lógica para Director de Carrera
        // Asumimos que el rol con ID 6 (o codigo DIRECTOR_CARRERA) es para directores.
        // Lo ideal es buscar por código, pero aqui usaremos el nombre del rol o codigo si esta cargado
        // O verificamos si el request trae 'rol_id' correspondiente.
        // Mejor: Si el rol tiene codigo 'DIRECTOR_CARRERA'.

        if ($user->rol && $user->rol->codigo === 'DIRECTOR_CARRERA') {
            // Crear perfil director
            $director = \App\Models\Director::create([
                'user_id' => $user->id,
                'nombres' => $user->nombre,
                'apellidos' => $user->apellido,
                'sede_id' => $validated['sede_id'] ?? null, // Asignar sede desde datos validados
                // 'carrera_id' => ... asignamos la primera como principal?
            ]);

            // Asignar carreras (ids vienen en $validated['carrera'] como string "1, 2" o array si el validador lo permitiera)
            // En el store frontend hicimos .join(', '). Recibimos "1, 2".
            $carreraString = trim($validated['carrera'] ?? '');
            if ($carreraString !== '') {
                $carreraIds = explode(',', $carreraString);
                $carreraIds = array_map('trim', $carreraIds);
                $carreraIds = array_filter($carreraIds, function ($id) {
                    return is_numeric($id) && $id > 0;
                });

                if (!empty($carreraIds)) {
                    // Sincronizar tabla pivot (muchos-a-muchos)
                    $director->carreras()->sync($carreraIds);
                    
                    // Establecer carrera principal (primera de la lista) en campo legacy
                    $director->carrera_id = $carreraIds[0];
                    $director->save();
                    
                    // Sincronizar campos legacy en carreras (director_id) usando observers
                    $directorObserver = new DirectorObserver();
                    $directorObserver->syncFromPivot($director);
                    
                    foreach ($carreraIds as $carreraId) {
                        $carrera = Carrera::find($carreraId);
                        if ($carrera) {
                            $carreraObserver = new CarreraObserver();
                            $carreraObserver->syncFromPivot($carrera);
                        }
                    }
                }
            }
        }

        return response()->json($user, 201);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'apellido' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'ci' => 'sometimes|string|max:20',
            'telefono' => 'nullable|string|max:20',
            'rol_id' => 'sometimes|exists:roles,id',
            'carrera' => 'nullable|string|max:255',
            'sede_id' => 'nullable|exists:sedes,id',
            'estado' => 'sometimes|string|in:activo,inactivo,true,false,1,0',
            'password' => 'nullable|string|min:6'
        ]);

        // Convertir estado a booleano si está presente
        if (isset($validated['estado'])) {
            $estado = $validated['estado'];
            if (in_array($estado, ['activo', 'true', '1'], true)) {
                $validated['estado'] = true;
            } elseif (in_array($estado, ['inactivo', 'false', '0'], true)) {
                $validated['estado'] = false;
            } else {
                // Mantener el valor actual del usuario
                unset($validated['estado']);
            }
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);
        $user->load('rol');

        // Sync Director Data
        if ($user->rol && $user->rol->codigo === 'DIRECTOR_CARRERA') {
            // Update or Create Director profile
            $director = \App\Models\Director::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'nombres' => $user->nombre,
                    'apellidos' => $user->apellido,
                    'sede_id' => $validated['sede_id'] ?? $user->sede_id
                ]
            );

            // Update fields
            $director->update([
                'nombres' => $validated['nombre'] ?? $director->nombres,
                'apellidos' => $validated['apellido'] ?? $director->apellidos,
                'sede_id' => $validated['sede_id'] ?? $director->sede_id
            ]);

            // Sync Carreras
            if (isset($validated['carrera'])) { // Si se envió el campo carrera
                $carreraString = trim($validated['carrera']);
                if ($carreraString !== '') {
                    $carreraIds = explode(',', $carreraString);
                    $carreraIds = array_map('trim', $carreraIds);
                    $carreraIds = array_filter($carreraIds, function ($id) {
                        return is_numeric($id) && $id > 0;
                    });

                    if (!empty($carreraIds)) {
                        // Sincronizar tabla pivot (muchos-a-muchos)
                        $director->carreras()->sync($carreraIds);
                        
                        // Establecer carrera principal (primera de la lista) en campo legacy
                        $director->carrera_id = $carreraIds[0];
                        $director->save();
                        
                        // Sincronizar campos legacy en carreras (director_id) usando observers
                        $directorObserver = new DirectorObserver();
                        $directorObserver->syncFromPivot($director);
                        
                        foreach ($carreraIds as $carreraId) {
                            $carrera = Carrera::find($carreraId);
                            if ($carrera) {
                                $carreraObserver = new CarreraObserver();
                                $carreraObserver->syncFromPivot($carrera);
                            }
                        }
                    } else {
                        // Si se envió cadena vacía (sin IDs), desvincular todas las carreras
                        $director->carreras()->sync([]);
                        $director->carrera_id = null;
                        $director->save();
                    }
                } else {
                    // Cadena vacía: desvincular todas las carreras
                    $director->carreras()->sync([]);
                    $director->carrera_id = null;
                    $director->save();
                }
            }
        }

        return response()->json($user);
    }

    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return response()->json(null, 204);
    }

    private function generateUniqueUsername($base)
    {
        $username = $base;
        $count = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base . $count;
            $count++;
        }
        return $username;
    }

    public function resetPassword(Request $request, $id)
    {
        // Verificar permisos (Middleware ya protege la ruta, pero doble check si se requiere rol Admin)
        // Por ahora asumimos que solo admin entra aqui

        $user = User::findOrFail($id);

        if (!$user->ci) {
            return response()->json(['message' => 'El usuario no tiene CI registrado'], 422);
        }

        $user->password = Hash::make($user->ci);
        $user->save();

        return response()->json(['message' => 'Contraseña restablecida correctamente al CI: ' . $user->ci]);
    }
}
