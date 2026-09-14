<?php

namespace App\Http\V1\Controllers;

use App\Models\Empresa;
use App\Models\Membresia;
use App\Soporte\ContextoRls;
use App\Soporte\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sesión de la app de empresa (AUT-01, AUT-05, AUT-06).
 */
class SesionController extends Controller
{
    private const INTENTOS_MAXIMOS = 5;

    public function iniciar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        // Bloqueo progresivo por cuenta y por IP (SEG-05, SEG-30).
        $llave = 'acceso:'.mb_strtolower($datos['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($llave, self::INTENTOS_MAXIMOS)) {
            $segundos = RateLimiter::availableIn($llave);

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Vuelve a intentar en {$segundos} segundos.",
            ])->status(429);
        }

        if (! Auth::guard('empresa')->attempt(['email' => $datos['email'], 'password' => $datos['password'], 'activo' => true])) {
            RateLimiter::hit($llave, 900);

            // No revela si el correo existe (SEG-08).
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden.',
            ]);
        }

        RateLimiter::clear($llave);

        $usuario = Auth::guard('empresa')->user();
        ContextoRls::fijar(ContextoRls::USUARIO, $usuario->id);

        $membresias = $this->membresiasActivas($usuario->id);

        if ($membresias->isEmpty()) {
            // Un usuario sin membresía no entra a la app de empresa (ADM-20).
            Auth::guard('empresa')->logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => 'Tu cuenta no tiene ninguna empresa asignada. Comunícate con quien administra la cuenta.',
            ])->status(403);
        }

        $request->session()->regenerate();   // SEG-03

        // Con una sola empresa se entra directo; con varias, la elige el usuario (EMP-05).
        if ($membresias->count() === 1) {
            $this->activarEmpresa($request, $membresias->first());
        }

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

    /** Cambiar de empresa emite una sesión nueva y descarta la caché del cliente (ARQ-06). */
    public function cambiarEmpresa(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'empresa' => ['required', 'string', 'size:26'],
        ]);

        $membresia = $this->membresiasActivas($request->user()->id)
            ->first(fn (Membresia $m) => $m->empresa->ulid === $datos['empresa']);

        if (! $membresia) {
            // No confirma si la empresa existe (ARQ-21).
            throw ValidationException::withMessages([
                'empresa' => 'No encontrada.',
            ])->status(404);
        }

        $request->session()->regenerate();
        $this->activarEmpresa($request, $membresia);

        return response()->json($this->estadoDeSesion($request));
    }

    public function cerrar(Request $request): JsonResponse
    {
        Auth::guard('empresa')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        ContextoRls::limpiar();

        return response()->json(['mensaje' => 'Sesión cerrada.']);
    }

    private function activarEmpresa(Request $request, Membresia $membresia): void
    {
        $request->session()->put('empresa_id', $membresia->empresa_id);
        ContextoRls::fijar(ContextoRls::EMPRESA, $membresia->empresa_id);
    }

    /** @return \Illuminate\Support\Collection<int,Membresia> */
    private function membresiasActivas(int $usuarioId)
    {
        return Membresia::query()
            ->with('empresa')
            ->where('usuario_id', $usuarioId)
            ->where('activa', true)
            ->get()
            ->filter(fn (Membresia $m) => $m->empresa !== null)
            ->values();
    }

    private function estadoDeSesion(Request $request): array
    {
        $usuario = $request->user();

        if (! $usuario) {
            return ['autenticado' => false];
        }

        $membresias = $this->membresiasActivas($usuario->id);
        $empresaId = $request->session()->get('empresa_id');
        $activa = $membresias->first(fn (Membresia $m) => $m->empresa_id === $empresaId);

        return [
            'autenticado' => true,
            'usuario' => [
                'id' => $usuario->ulid,
                'nombres' => $usuario->nombres,
                'apellidos' => $usuario->apellidos,
                'email' => $usuario->email,
            ],
            'empresa_activa' => $activa ? $this->empresaComoArreglo($activa) : null,
            'empresas' => $membresias->map(fn (Membresia $m) => $this->empresaComoArreglo($m))->all(),
        ];
    }

    private function empresaComoArreglo(Membresia $membresia): array
    {
        /** @var Empresa $empresa */
        $empresa = $membresia->empresa;

        return [
            'id' => $empresa->ulid,
            'nombre' => $empresa->nombre(),
            'pais' => $empresa->pais,
            'moneda' => $empresa->moneda,
            'estado' => $empresa->estado,
            'solo_lectura' => ! $empresa->permiteEscritura(),
            'rol' => $membresia->rol,
            'perfil' => $membresia->perfil,
            // Lo que la interfaz puede mostrar. El servidor lo vuelve a verificar
            // en cada endpoint: esto no es el permiso, es la pista (ROL-03).
            'permisos' => Permisos::de($membresia),
        ];
    }
}
