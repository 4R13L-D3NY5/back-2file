<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $allowedRoles = collect($roles)
            ->flatMap(fn($roleChunk) => explode(',', (string) $roleChunk))
            ->map(fn($role) => trim($role))
            ->filter()
            ->values();

        if ($allowedRoles->isEmpty()) {
            $allowedRoles = collect(['SUPER_ADMIN']);
        }

        if (!$user->rol || !$allowedRoles->contains($user->rol->codigo)) {
            return response()->json(['message' => 'Forbidden: insufficient permissions'], 403);
        }

        return $next($request);
    }
}
