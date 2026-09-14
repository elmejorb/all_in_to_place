<?php

namespace App\Auth;

use App\Soporte\ContextoRls;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Proveedor de usuarios que abre el contexto justo antes de consultar.
 *
 * Sin esto la autenticación sería imposible: la política de `usuario` no deja
 * leer filas sin contexto. Aquí se abre lo mínimo — la fila del correo que se
 * está autenticando, o la del usuario que ya probó su identidad con la sesión—
 * y nada más.
 */
class ProveedorUsuarioRls extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        ContextoRls::fijar(ContextoRls::USUARIO, $identifier);

        return parent::retrieveById($identifier);
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        ContextoRls::fijar(ContextoRls::USUARIO, $identifier);

        return parent::retrieveByToken($identifier, $token);
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        if (! empty($credentials['email'])) {
            // Una sola fila: la del correo del intento (SEG-08, política usuario_app).
            ContextoRls::fijar(ContextoRls::LOGIN_EMAIL, $credentials['email']);
        }

        $usuario = parent::retrieveByCredentials($credentials);

        ContextoRls::fijar(ContextoRls::LOGIN_EMAIL, '');

        return $usuario;
    }
}
