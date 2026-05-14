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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
            'sede',
            'campus.sede',
            'campusAsignados.sede'
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
            // Resolver Carrera
            $carreraNombre = null;

            // 1. Si es Director, ver perfil (Priorizar tabla pivot)
            if ($user->director) {
                if ($user->director->carreras->isNotEmpty()) {
                    $carreraNombre = $user->director->carreras->pluck('nombre')->implode(', ');
                } elseif ($user->director->carrera) {
                    $carreraNombre = $user->director->carrera->nombre;
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
            $this->anexarAmbitoUsuario($user);
            return $user;
        });

        return response()->json($users);
    }

    public function store(Request $request)
    {
        Log::info("Store Request:", $request->all());
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
            'estado' => 'sometimes'
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

        DB::beginTransaction();
        try {
            // Password default es el CI
            $validated['password'] = Hash::make($validated['ci']);
            $validated['password_change_required'] = false;

            // Generar username automaticamente
            $baseUsername = Str::slug($validated['nombre'] . '.' . $validated['apellido']);
            $validated['username'] = $this->generateUniqueUsername($baseUsername);

            $user = User::create($validated);
            $user->load('rol');

            // Lógica para Director de Carrera
            if ($user->rol && $user->rol->codigo === 'DIRECTOR_CARRERA') {
                $director = \App\Models\Director::create([
                    'user_id' => $user->id,
                    'nombres' => $user->nombre,
                    'apellidos' => $user->apellido,
                    'sede_id' => $validated['sede_id'] ?? null,
                ]);

                // Asignar carreras
                if (isset($validated['carrera'])) {
                    $carreraString = trim($validated['carrera']);
                    if ($carreraString !== '') {
                        $carreraIds = array_map('trim', explode(',', $carreraString));
                        $carreraIds = array_filter($carreraIds, function ($id) {
                            return is_numeric($id) && $id > 0;
                        });

                        if (!empty($carreraIds)) {
                            // Sincronizar tabla pivot
                            $syncData = [];
                            foreach ($carreraIds as $index => $id) {
                                $syncData[$id] = ['es_principal' => ($index === 0)];
                            }
                            $director->carreras()->sync($syncData);
                            
                            // Campo legacy
                            $director->carrera_id = $carreraIds[0];
                            $director->save();
                            
                            // users.carrera
                            $user->carrera = implode(', ', $carreraIds);
                            $user->save();
                        }
                    }
                }
            }

            DB::commit();
            
            $user->load([
                'rol',
                'director.carrera',
                'director.carreras',
                'director.sede',
                'docente.sede',
                'sede',
                'campus.sede',
                'campusAsignados.sede'
            ]);
            
            // Formatear respuesta
            if ($user->director && $user->director->carreras) {
                $carreraNombre = $user->director->carreras->pluck('nombre')->implode(', ');
                $user->setAttribute('carrera_nombre', $carreraNombre);
            }
            $this->anexarAmbitoUsuario($user);

            return response()->json($user, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en UserController@store: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return response()->json([
                'message' => 'Error al crear usuario',
                'error' => $e->getMessage()
            ], 500);
        }

        return response()->json($user, 201);
    }

    public function update(Request $request, string $id)
    {
        \Log::info("Update Request para usuario {$id}:", $request->all());
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
            'estado' => 'sometimes',
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

        DB::transaction(function () use ($user, $validated) {
            $user->update($validated);
            $user->load('rol');

            // Sync Director Data
            if ($user->rol && $user->rol->codigo === 'DIRECTOR_CARRERA') {
                $director = \App\Models\Director::firstOrCreate(['user_id' => $user->id]);

                // Update fields
                $director->update([
                    'nombres' => $validated['nombre'] ?? $director->nombres,
                    'apellidos' => $validated['apellido'] ?? $director->apellidos,
                    'sede_id' => $validated['sede_id'] ?? $director->sede_id
                ]);

                // Sync Carreras
                if (isset($validated['carrera'])) {
                    $carreraString = trim($validated['carrera']);

                    if ($carreraString !== '') {
                        $carreraIds = array_map('trim', explode(',', $carreraString));
                        $carreraIds = array_filter($carreraIds, function ($id) {
                            return is_numeric($id) && $id > 0;
                        });

                        if (!empty($carreraIds)) {
                            $syncData = [];
                            foreach ($carreraIds as $index => $id) {
                                $syncData[$id] = ['es_principal' => ($index === 0)];
                            }
                            $director->carreras()->sync($syncData);
                            
                            $director->carrera_id = $carreraIds[0];
                            $director->save();
                            
                            // Asegurar que users.carrera tenga la misma cadena
                            $user->carrera = implode(', ', $carreraIds);
                            $user->save();
                        } else {
                            $director->carreras()->sync([]);
                            $director->carrera_id = null;
                            $director->save();
                            $user->carrera = null;
                            $user->save();
                        }
                    } else {
                        $director->carreras()->sync([]);
                        $director->carrera_id = null;
                        $director->save();
                        $user->carrera = null;
                        $user->save();
                    }
                }
            }
        });

        $user->load([
            'rol',
            'director.carrera',
            'director.carreras',
            'director.sede',
            'docente.sede',
            'sede',
            'campus.sede',
            'campusAsignados.sede'
        ]);
        
        // Formatear respuesta igual que en index para el Store de Quasar
        if ($user->director && $user->director->carreras) {
            $carreraNombre = $user->director->carreras->pluck('nombre')->implode(', ');
            $user->setAttribute('carrera_nombre', $carreraNombre);
        }
        $this->anexarAmbitoUsuario($user);
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

    private function anexarAmbitoUsuario(User $user)
    {
        $campusAsignados = $user->campusAsignados;

        if ($user->campus && !$campusAsignados->contains('id', $user->campus->id)) {
            $campusAsignados = $campusAsignados->push($user->campus);
        }

        $campusAsignados = $campusAsignados->unique('id')->values();

        $sedes = collect([
            $user->sede,
            $user->docente ? $user->docente->sede : null,
            $user->director ? $user->director->sede : null,
        ])->filter();

        $sedes = $sedes->merge(
            $campusAsignados
                ->map(function ($campus) {
                    return $campus->sede;
                })
                ->filter()
        )->unique('id')->values();

        $sedePrincipal = $user->sede_id
            ? $sedes->firstWhere('id', $user->sede_id)
            : $sedes->first();

        $user->setAttribute('sede_id', $sedePrincipal ? $sedePrincipal->id : $user->sede_id);
        $user->setAttribute('sede_nombre', $sedes->pluck('nombre')->filter()->implode(', '));
        $user->setAttribute('sede_ids', $sedes->pluck('id')->values()->all());
        $user->setAttribute('sedes_asignadas', $sedes
            ->map(function ($sede) {
                return [
                    'id' => $sede->id,
                    'nombre' => $sede->nombre,
                ];
            })
            ->values()
            ->all());
        $user->setAttribute('campus_ids', $campusAsignados->pluck('id')->values()->all());
        $user->setAttribute('campus_asignados', $campusAsignados
            ->map(function ($campus) {
                return [
                    'id' => $campus->id,
                    'nombre' => $campus->nombre,
                    'sede_id' => $campus->sede_id,
                    'sede' => $campus->sede ? $campus->sede->nombre : null,
                ];
            })
            ->values()
            ->all());

        return $user;
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
