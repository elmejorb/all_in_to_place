<?php

namespace App\Http\Middleware;

use App\Models\Membresia;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Todo endpoint de negocio exige una empresa activa en la sesión.
 *
 * Y si la empresa está suspendida o cancelada, deja pasar las lecturas pero no
 * las escrituras: la cuenta queda en solo lectura, con sus datos intactos y
 * exportables (ADM-03).
 */
class ExigirEmpresa
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Membresia|null $membresia */
        $membresia = $request->attributes->get('membresia');

        if (! $membresia) {
            return response()->json([
                'message' => 'Elige una empresa para continuar.',
                'codigo' => 'sin_empresa',
            ], 409);
        }

        $esEscritura = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($esEscritura && ! $membresia->empresa->permiteEscritura()) {
            return response()->json([
                'message' => 'Esta empresa está '.$membresia->empresa->estado.'. Puedes consultar y exportar, pero no registrar cambios.',
                'codigo' => 'solo_lectura',
            ], 423);
        }

        return $next($request);
    }
}
