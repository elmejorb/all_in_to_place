<?php

namespace App\Soporte;

use App\Models\Membresia;

/**
 * La matriz de permisos de docs/04-roles-y-permisos.md, en un solo lugar.
 *
 * Se verifica siempre en el servidor: ocultar un botón en la interfaz no es un
 * permiso (ROL-03, SEG-12).
 */
final class Permisos
{
    // Catálogo: suplidores, categorías, productos.
    public const CATALOGO_VER = 'catalogo.ver';
    public const CATALOGO_EDITAR = 'catalogo.editar';
    public const CATALOGO_DESACTIVAR = 'catalogo.desactivar';

    // Costos y márgenes: no todos los roles ven lo que cuesta la mercancía.
    public const COSTOS_VER = 'costos.ver';

    public const EMPRESA_CONFIGURAR = 'empresa.configurar';
    public const USUARIOS_GESTIONAR = 'usuarios.gestionar';

    /** @var array<string, list<string>> */
    private const MATRIZ = [
        'propietario' => [
            self::CATALOGO_VER, self::CATALOGO_EDITAR, self::CATALOGO_DESACTIVAR,
            self::COSTOS_VER, self::EMPRESA_CONFIGURAR, self::USUARIOS_GESTIONAR,
        ],
        'administrador' => [
            self::CATALOGO_VER, self::CATALOGO_EDITAR, self::CATALOGO_DESACTIVAR,
            self::COSTOS_VER, self::USUARIOS_GESTIONAR,
        ],
        'gerente' => [
            self::CATALOGO_VER, self::CATALOGO_EDITAR, self::COSTOS_VER,
        ],
        // El empleado de almacén ve el catálogo; el de mostrador no entra aquí.
        'empleado' => [
            self::CATALOGO_VER,
        ],
        'contratista' => [],
        'contador' => [
            self::CATALOGO_VER, self::COSTOS_VER,
        ],
    ];

    /** Permisos que solo tiene el empleado con perfil de almacén (ROL-08). */
    private const SOLO_ALMACEN = [self::CATALOGO_VER];

    public static function permite(?Membresia $membresia, string $permiso): bool
    {
        if (! $membresia || ! $membresia->activa) {
            return false;
        }

        $delRol = self::MATRIZ[$membresia->rol] ?? [];

        if (! in_array($permiso, $delRol, true)) {
            return false;
        }

        if ($membresia->rol === 'empleado' && in_array($permiso, self::SOLO_ALMACEN, true)) {
            return $membresia->perfil === 'almacen';
        }

        return true;
    }

    /** Lo que la interfaz puede mostrar. No sustituye la verificación por endpoint. */
    public static function de(?Membresia $membresia): array
    {
        if (! $membresia) {
            return [];
        }

        return array_values(array_filter(
            self::MATRIZ[$membresia->rol] ?? [],
            fn (string $permiso) => self::permite($membresia, $permiso),
        ));
    }
}
