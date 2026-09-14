<?php

namespace App\Http\Middleware;

use App\Models\Membresia;
use App\Soporte\ContextoRls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la empresa activa desde la sesión del servidor y abre el contexto de
 * aislamiento (ARQ-05).
 *
 * Nunca lee la empresa de la URL, del cuerpo ni de una cabecera. Si el usuario
 * ya no tiene membresía activa en la empresa guardada, el contexto no se abre y
 * la base no devuelve una sola fila de esa empresa.
 */
class EstablecerContextoEmpresa
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario) {
            return $next($request);
        }

        ContextoRls::fijar(ContextoRls::USUARIO, $usuario->id);

        $empresaId = $request->session()->get('empresa_id');

        if ($empresaId) {
            $membresia = Membresia::query()
                ->where('empresa_id', $empresaId)
                ->where('usuario_id', $usuario->id)
                ->where('activa', true)
                ->first();

            if ($membresia) {
                ContextoRls::fijar(ContextoRls::EMPRESA, $empresaId);
                $request->attributes->set('membresia', $membresia);
            } else {
                // La membresía se revocó mientras la sesión seguía viva (ROL-05).
                $request->session()->forget('empresa_id');
                ContextoRls::fijar(ContextoRls::EMPRESA, '');
            }
        }

        return $next($request);
    }
}
