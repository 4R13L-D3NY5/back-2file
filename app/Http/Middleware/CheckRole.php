<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $roles = 'SUPER_ADMIN'): Response
    {
        Log::info('CheckRole middleware invoked', [
            'path' => $request->path(),
            'full_url' => $request->fullUrl(),
            'method' => $request->method(),
            'roles_required' => $roles,
            'roles_required_raw' => $roles,
            'roles_array' => array_map('trim', explode(',', $roles)),
            'user_id' => $request->user()?->id,
        ]);
        
        $user = $request->user();
        
        if (!$user) {
            Log::warning('CheckRole: unauthenticated user');
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        
        // Split roles by comma and trim whitespace
        $allowedRoles = array_map('trim', explode(',', $roles));
        
        // Check if user has a role
        if (!$user->rol) {
            Log::warning('CheckRole: user has no role assigned', ['user_id' => $user->id]);
            return response()->json(['message' => 'Forbidden: user has no role assigned'], 403);
        }
        
        // SUPER_ADMIN tiene acceso a todo
        if ($user->rol->codigo === 'SUPER_ADMIN') {
            Log::info('CheckRole: SUPER_ADMIN granted access', [
                'user_id' => $user->id,
                'allowed_roles' => $allowedRoles
            ]);
            return $next($request);
        }
        
        // Check if user's role is in allowed roles
        if (!in_array($user->rol->codigo, $allowedRoles)) {
            Log::warning('CheckRole: insufficient permissions', [
                'user_id' => $user->id,
                'user_role' => $user->rol->codigo,
                'allowed_roles' => $allowedRoles
            ]);
            return response()->json([
                'message' => 'Forbidden: insufficient permissions',
                'required_roles' => $allowedRoles,
                'user_role' => $user->rol->codigo
            ], 403);
        }
        
        Log::info('CheckRole: access granted', [
            'user_id' => $user->id,
            'user_role' => $user->rol->codigo
        ]);
        
        return $next($request);
    }
}
