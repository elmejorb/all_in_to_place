<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Autenticable;
use Illuminate\Support\Str;

/**
 * Usuario de la consola de plataforma (ROL-04).
 *
 * Tabla aparte, guard aparte y sesión aparte: una sesión de la consola no vale
 * en la app de empresa ni al revés (ARQ-03).
 */
class UsuarioPlataforma extends Autenticable implements Authenticatable
{
    use SoftDeletes;

    protected $table = 'usuario_plataforma';
    public const DELETED_AT = 'borrado_en';

    public const ROLES = ['superadmin', 'soporte', 'comercial'];

    protected $fillable = ['nombres', 'apellidos', 'email', 'password', 'rol', 'activo'];

    protected $hidden = ['password', 'remember_token', 'totp_secreto'];

    protected function casts(): array
    {
        return [
            'ultimo_acceso_en' => 'datetime',
            'password' => 'hashed',
            'totp_secreto' => 'encrypted',  // SEG-19
            'activo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $u) {
            if (empty($u->ulid)) {
                $u->ulid = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }
}
