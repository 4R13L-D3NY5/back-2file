<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNationalAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $allowedRoles = ['SUPER_ADMIN', 'ADMIN'];

        if (!$user->rol || !in_array($user->rol->codigo, $allowedRoles)) {
            return response()->json(['message' => 'Forbidden: solo administradores nacionales'], 403);
        }

        return $next($request);
    }
}
