<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF con nombre de cookie por aplicación (SEG-26).
 *
 * La versión del framework escribe siempre `XSRF-TOKEN`. Con las dos apps en
 * `localhost` esa cookie sería compartida y cada una invalidaría el token de la
 * otra.
 */
class ValidarCsrf extends ValidateCsrfToken
{
    protected function newCookie($request, $config): Cookie
    {
        return new Cookie(
            config('session.csrf_cookie', 'XSRF-TOKEN'),
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }

    public function handle($request, \Closure $next): Response
    {
        return parent::handle($request, $next);
    }
}
