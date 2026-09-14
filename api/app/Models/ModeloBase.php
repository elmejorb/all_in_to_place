<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Base de todos los modelos del dominio.
 *
 * Cada tabla lleva dos claves: `id` bigint interno para índices y llaves
 * foráneas, que no sale nunca de la base, y `ulid` de 26 caracteres como único
 * identificador público (ARQ-13). Las rutas resuelven por `ulid`, así que un
 * identificador no es adivinable ni recorrible.
 */
abstract class ModeloBase extends Model
{
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    protected static function booted(): void
    {
        static::creating(function (self $modelo) {
            if (empty($modelo->ulid)) {
                $modelo->ulid = (string) Str::ulid();
            }
        });
    }

    /** Las rutas y la API hablan en ULID, nunca en el id interno. */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
