<?php

namespace App\Soporte;

use Illuminate\Support\Facades\DB;

/**
 * Fija el contexto que leen las políticas de aislamiento de PostgreSQL (ARQ-04).
 *
 * El valor sale siempre de la sesión del servidor, nunca de un parámetro del
 * navegador (ARQ-05). Se fija a nivel de sesión de conexión: Laravel abre una
 * conexión por petición, así que el contexto muere con la petición. Si algún día
 * se activan conexiones persistentes, esto tiene que pasar a `SET LOCAL` dentro
 * de una transacción por petición.
 */
final class ContextoRls
{
    public const EMPRESA = 'app.empresa_id';
    public const USUARIO = 'app.usuario_id';
    public const LOGIN_EMAIL = 'app.login_email';

    public static function fijar(string $clave, int|string|null $valor): void
    {
        DB::select('select set_config(?, ?, false)', [$clave, (string) ($valor ?? '')]);
    }

    public static function limpiar(): void
    {
        foreach ([self::EMPRESA, self::USUARIO, self::LOGIN_EMAIL] as $clave) {
            self::fijar($clave, '');
        }
    }

    public static function empresaActual(): ?int
    {
        $valor = DB::select('select current_setting(?, true) as v', [self::EMPRESA])[0]->v ?? null;

        return $valor === null || $valor === '' ? null : (int) $valor;
    }
}
