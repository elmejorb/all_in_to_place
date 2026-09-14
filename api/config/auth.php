<?php

use App\Models\Usuario;
use App\Models\UsuarioPlataforma;

return [

    // El guard por defecto lo fija ConfigurarAplicacion según la aplicación (ARQ-03).
    'defaults' => [
        'guard' => 'empresa',
        'passwords' => 'usuarios',
    ],

    'guards' => [
        'empresa' => [
            'driver' => 'session',
            'provider' => 'usuarios',
        ],

        'plataforma' => [
            'driver' => 'session',
            'provider' => 'usuarios_plataforma',
        ],
    ],

    'providers' => [
        'usuarios' => [
            'driver' => 'usuario_rls',
            'model' => Usuario::class,
        ],

        'usuarios_plataforma' => [
            'driver' => 'usuario_rls',
            'model' => UsuarioPlataforma::class,
        ],
    ],

    'passwords' => [
        'usuarios' => [
            'provider' => 'usuarios',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
