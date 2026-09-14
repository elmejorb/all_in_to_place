<?php

use App\Http\Middleware\ConfigurarAplicacion;
use App\Http\Middleware\EstablecerContextoEmpresa;
use App\Http\Middleware\ValidarCsrf;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Dos superficies, dos grupos de middleware, ninguna ruta compartida (ARQ-02).
            Route::middleware('app-empresa')
                ->prefix('v1')
                ->group(base_path('routes/v1.php'));

            Route::middleware('consola')
                ->prefix('v1/admin')
                ->group(base_path('routes/v1admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->group('app-empresa', [
            ConfigurarAplicacion::class.':empresa',
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            ValidarCsrf::class,
            // El contexto se abre ANTES de resolver los modelos de la ruta: si no,
            // el enlace {suplidor} consulta sin empresa y no encuentra nada.
            EstablecerContextoEmpresa::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->group('consola', [
            ConfigurarAplicacion::class.':consola',
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            ValidarCsrf::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // La API contesta siempre en JSON: no hay vistas ni redirecciones a login.
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
