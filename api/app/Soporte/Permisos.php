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

    // Ver la lista de productos con su precio es otra cosa que ver el catálogo
    // entero. Quien está en el mostrador lo necesita —no se puede vender lo que
    // no se puede buscar— pero no tiene por qué ver suplidores ni costos.
    public const PRODUCTOS_VER = 'productos.ver';
    public const CATALOGO_EDITAR = 'catalogo.editar';
    public const CATALOGO_DESACTIVAR = 'catalogo.desactivar';

    // Clientes. El de mostrador los necesita para facturar, así que crea al
    // vuelo (CLI-02), pero no administra su crédito ni su exención.
    public const CLIENTES_VER = 'clientes.ver';
    public const CLIENTES_EDITAR = 'clientes.editar';

    // Facturación. Anular es aparte de facturar: el cajero vende, pero no
    // deshace una venta ya emitida (matriz del documento 04).
    public const FACTURAR = 'facturas.emitir';
    public const FACTURAS_ANULAR = 'facturas.anular';

    // Costos y márgenes: no todos los roles ven lo que cuesta la mercancía.
    public const COSTOS_VER = 'costos.ver';

    public const EMPRESA_CONFIGURAR = 'empresa.configurar';
    public const USUARIOS_GESTIONAR = 'usuarios.gestionar';

    /** @var array<string, list<string>> */
    private const MATRIZ = [
        'propietario' => [
            self::CATALOGO_VER, self::PRODUCTOS_VER, self::CATALOGO_EDITAR, self::CATALOGO_DESACTIVAR,
            self::CLIENTES_VER, self::CLIENTES_EDITAR,
            self::FACTURAR, self::FACTURAS_ANULAR,
            self::COSTOS_VER, self::EMPRESA_CONFIGURAR, self::USUARIOS_GESTIONAR,
        ],
        'administrador' => [
            self::CATALOGO_VER, self::PRODUCTOS_VER, self::CATALOGO_EDITAR, self::CATALOGO_DESACTIVAR,
            self::CLIENTES_VER, self::CLIENTES_EDITAR,
            self::FACTURAR, self::FACTURAS_ANULAR,
            self::COSTOS_VER, self::USUARIOS_GESTIONAR,
        ],
        'gerente' => [
            self::CATALOGO_VER, self::PRODUCTOS_VER, self::CATALOGO_EDITAR,
            self::CLIENTES_VER, self::CLIENTES_EDITAR,
            self::FACTURAR, self::FACTURAS_ANULAR,
            self::COSTOS_VER,
        ],
        // El de almacén ve el catálogo; el de mostrador ve y crea clientes,
        // porque sin eso no puede facturar (ROL-08, CLI-02).
        'empleado' => [
            self::CATALOGO_VER, self::PRODUCTOS_VER,
            self::CLIENTES_VER, self::CLIENTES_EDITAR, self::FACTURAR,
        ],
        'contratista' => [
            self::CLIENTES_VER,
        ],
        'contador' => [
            self::CATALOGO_VER, self::PRODUCTOS_VER, self::CLIENTES_VER, self::COSTOS_VER,
        ],
    ];

    /** Permisos que solo tiene el empleado con perfil de almacén (ROL-08). */
    private const SOLO_ALMACEN = [self::CATALOGO_VER];

    /** Y los que solo tiene el de mostrador. */
    private const SOLO_MOSTRADOR = [self::CLIENTES_VER, self::CLIENTES_EDITAR, self::FACTURAR];

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

        if ($membresia->rol === 'empleado' && in_array($permiso, self::SOLO_MOSTRADOR, true)) {
            return $membresia->perfil === 'mostrador';
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
