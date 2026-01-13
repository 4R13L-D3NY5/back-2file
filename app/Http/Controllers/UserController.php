<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('rol')
            ->orderBy('id', 'desc');

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
            'estado' => 'boolean'
        ]);

        // Generar username automaticamente si no viene, e.g. nombre.apellido
        if (!$request->has('username')) {
            $baseUsername = Str::slug($validated['nombre'] . '.' . $validated['apellido']);
            $validated['username'] = $this->generateUniqueUsername($baseUsername);
        } else {
            $request->validate(['username' => 'required|string|unique:users,username']);
            $validated['username'] = $request->username;
        }

        // Password default 'password' if not set, else hash it
        if ($request->has('password') && !empty($request->password)) {
            $validated['password'] = Hash::make($request->password);
        } else {
            $validated['password'] = Hash::make('password');
        }

        $user = User::create($validated);
        $user->load('rol');

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
            'estado' => 'boolean',
            'password' => 'nullable|string|min:6'
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);
        $user->load('rol');

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
}
