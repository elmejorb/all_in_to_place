<?php

namespace App\Providers;

use App\Auth\ProveedorUsuarioRls;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Proveedor que abre el contexto de aislamiento antes de consultar (ARQ-04).
        Auth::provider('usuario_rls', function ($app, array $config) {
            return new ProveedorUsuarioRls($app['hash'], $config['model']);
        });

        if (! $this->app->isLocal()) {
            URL::forceScheme('https');   // SEG-17
        }
    }
}
