<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login - Acepta email O username (CI)
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);

        $loginField = $request->username;

        // Buscar por email o username (CI)
        $user = User::where('email', $loginField)
            ->orWhere('username', $loginField)
            ->orWhere('ci', $loginField)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Credenciales incorrectas.'],
            ]);
        }

        if (! $user->estado) {
            throw ValidationException::withMessages([
                'username' => ['El usuario está inactivo.'],
            ]);
        }

        // Crear Token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Cargar relaciones profundas para cálculo de progreso
        $user->load([
            'rol',
            'docente.asignaturas.unidades.temas',
            'docente.asignaturas.carreras',
            'docente.sede',
            'docente.grupos',
            'director.sede',
            'director.carrera'
        ]);

        // Append progress
        if ($user->docente) {
            $user->docente->asignaturas->each(function ($asignatura) {
                $asignatura->append(['progreso', 'estadisticas_progreso']);
            });
        }

        $passwordChangeRequired = (bool) $user->password_change_required;

        // Si la contraseña coincide con el CI (escenario común en primer inicio), forzar cambio
        if (!$passwordChangeRequired && $user->ci && Hash::check($user->ci, $user->password)) {
            $passwordChangeRequired = true;
        }

        return response()->json([
            'message' => 'Login exitoso',
            'token' => $token,
            'user' => $user,
            'password_change_required' => $passwordChangeRequired,
        ]);
    }

    /**
     * Actualizar perfil del usuario
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'nombre' => 'required|string|max:255',
            'apellido' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'ci' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:20',
        ]);

        // Actualizar Usuario
        $user->update($request->only(['nombre', 'apellido', 'email', 'ci', 'telefono']));

        // Sincronizar con el modelo Docente si existe
        if ($user->docente) {
            $user->docente->update([
                'nombre_completo' => "{$user->nombre} {$user->apellido}",
                'ci' => $user->ci,
                'email' => $user->email,
                'celular' => $user->telefono, // Mapear teléfono a celular en docente
            ]);
        }

        return response()->json([
            'message' => 'Perfil actualizado correctamente',
            'user' => $user->load(['rol', 'docente.sede', 'director'])
        ]);
    }

    /**
     * Cambiar contraseña
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:6|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual es incorrecta.'],
            ]);
        }

        if (Hash::check($request->new_password, $user->password)) {
            throw ValidationException::withMessages([
                'new_password' => ['La nueva contraseña no puede ser igual a la anterior.'],
            ]);
        }

        $user->password = Hash::make($request->new_password);
        $user->password_change_required = false;
        $user->save();

        return response()->json(['message' => 'Contraseña actualizada exitosamente.']);
    }

    /**
     * Register (Opcional, para testing rápido o admin)
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'rol_id' => 1,
            'password_change_required' => true,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada exitosamente']);
    }

    /**
     * Me (Perfil)
     */
    public function me(Request $request)
    {
        $user = $request->user()->load([
            'rol',
            'docente.asignaturas.unidades.temas', // Load deep relations for progress calc
            'docente.asignaturas.carreras',
            'docente.sede',
            'docente.grupos',
            'director'
        ]);

        // Append progress attribute to each asignatura
        if ($user->docente) {
            $user->docente->asignaturas->each(function ($asignatura) {
                $asignatura->append(['progreso', 'estadisticas_progreso']);
            });
        }

        $passwordChangeRequired = (bool) $user->password_change_required;
        if (!$passwordChangeRequired && $user->ci && Hash::check($user->ci, $user->password)) {
            $passwordChangeRequired = true;
        }

        $user->password_change_required = $passwordChangeRequired;
        return $user;
    }
}
