<?php

namespace App\Http\V1Admin\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sesión de la consola de plataforma (ROL-04).
 *
 * Guard, tabla, cookie y conexión distintos de los de la app de empresa: una
 * sesión de aquí no vale allá (ARQ-03).
 */
class SesionConsolaController extends Controller
{
    private const INTENTOS_MAXIMOS = 5;

    public function iniciar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $llave = 'acceso-consola:'.mb_strtolower($datos['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($llave, self::INTENTOS_MAXIMOS)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Vuelve a intentar en '.RateLimiter::availableIn($llave).' segundos.',
            ])->status(429);
        }

        if (! Auth::guard('plataforma')->attempt(['email' => $datos['email'], 'password' => $datos['password'], 'activo' => true])) {
            RateLimiter::hit($llave, 900);

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden.',
            ]);
        }

        RateLimiter::clear($llave);
        $request->session()->regenerate();

        $usuario = Auth::guard('plataforma')->user();
        $usuario->forceFill(['ultimo_acceso_en' => now()])->saveQuietly();

        return response()->json($this->estadoDeSesion($request));
    }

    public function yo(Request $request): JsonResponse
    {
        return response()->json($this->estadoDeSesion($request));
    }

    /** Público: contesta si hay sesión, sin exigirla. */
    public function estado(Request $request): JsonResponse
    {
        return response()->json($this->estadoDeSesion($request));
    }

    public function cerrar(Request $request): JsonResponse
    {
        Auth::guard('plataforma')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['mensaje' => 'Sesión cerrada.']);
    }

    private function estadoDeSesion(Request $request): array
    {
        $usuario = Auth::guard('plataforma')->user();

        if (! $usuario) {
            return ['autenticado' => false];
        }

        return [
            'autenticado' => true,
            'usuario' => [
                'id' => $usuario->ulid,
                'nombres' => $usuario->nombres,
                'apellidos' => $usuario->apellidos,
                'email' => $usuario->email,
                'rol' => $usuario->rol,
            ],
        ];
    }
}
