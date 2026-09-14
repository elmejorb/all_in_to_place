<?php

namespace App\Http\Middleware;

use App\Soporte\Permisos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica el permiso del rol en el servidor, por endpoint (ROL-03).
 */
class ExigirPermiso
{
    public function handle(Request $request, Closure $next, string $permiso): Response
    {
        if (! Permisos::permite($request->attributes->get('membresia'), $permiso)) {
            return response()->json([
                'message' => 'Tu rol no tiene acceso a esta acción.',
                'codigo' => 'sin_permiso',
            ], 403);
        }

        return $next($request);
    }
}
