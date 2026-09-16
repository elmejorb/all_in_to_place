<?php

/**
 * CORS restringido a los orígenes propios de cada aplicación (SEG-27).
 * Sin comodines: las dos SPA envían cookies de sesión.
 */
return [
    'paths' => ['v1/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter([
        env('URL_APP_EMPRESA'),
        env('URL_CONSOLA'),
    ]),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'Accept'],
    // Sin exponerla, el navegador no puede leerla y toda descarga se llamaría
    // 'archivo.csv' (SEG-27).
    'exposed_headers' => ['X-Nombre-Archivo'],
    'max_age' => 3600,
    'supports_credentials' => true,
];
