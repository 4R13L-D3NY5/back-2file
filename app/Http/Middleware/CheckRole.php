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
            ->map(fn($role) => $this->normalizeRoleCode($role))
            ->filter()
            ->values();

        if ($allowedRoles->isEmpty()) {
            $allowedRoles = collect(['SUPER_ADMIN']);
        }

        $userRole = $this->normalizeRoleCode($user->rol?->codigo);

        if (!$user->rol || !$allowedRoles->contains($userRole)) {
            return response()->json(['message' => 'Forbidden: insufficient permissions'], 403);
        }

        return $next($request);
    }

    private function normalizeRoleCode(?string $role): string
    {
        $code = trim((string) $role);

        if (str_contains($code, 'DIRECCI') && str_contains($code, 'ACAD')) {
            return 'DIRECCION_ACADEMICA';
        }

        return [
            'VICERRECTORADO_NACIONAL' => 'VICERRECTOR_NACIONAL',
            'VICERRECTORADO' => 'VICERRECTOR_SEDE',
            'DIRECCIÓN ACADÉMICA' => 'DIRECCION_ACADEMICA',
        ][$code] ?? $code;
    }
}
