<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Separa las dos aplicaciones antes de que arranque la sesión (ARQ-02, ARQ-03).
 *
 * Cada una tiene su propia cookie de sesión, su propia cookie de CSRF y su
 * propia conexión de base de datos. En producción viven además en dominios
 * distintos; en desarrollo comparten `localhost`, así que los nombres distintos
 * son lo único que impide que una pise la sesión de la otra.
 */
class ConfigurarAplicacion
{
    public function handle(Request $request, Closure $next, string $aplicacion): Response
    {
        if ($aplicacion === 'consola') {
            config([
                'session.cookie' => 'aiop_consola_sesion',
                'session.csrf_cookie' => 'AIOP-CONSOLA-XSRF',
                'auth.defaults.guard' => 'plataforma',
            ]);

            // La consola no ve datos de negocio: su rol de base de datos no tiene
            // política sobre esas tablas (ADM-08).
            DB::setDefaultConnection('pgsql_consola');
        } else {
            config([
                'session.cookie' => 'aiop_empresa_sesion',
                'session.csrf_cookie' => 'AIOP-EMPRESA-XSRF',
                'auth.defaults.guard' => 'empresa',
            ]);

            DB::setDefaultConnection('pgsql');
        }

        return $next($request);
    }
}
