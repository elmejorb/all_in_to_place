<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Autenticable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * Identidad de una persona que trabaja en una o varias empresas.
 *
 * No lleva empresa: el acceso lo da la membresía (AUT-05, ROL-09). Un usuario
 * sin ninguna membresía activa no puede entrar (ADM-20).
 */
class Usuario extends Autenticable implements Authenticatable
{
    use Notifiable, SoftDeletes;

    protected $table = 'usuario';
    public const DELETED_AT = 'borrado_en';

    protected $fillable = ['nombres', 'apellidos', 'email', 'password', 'activo'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verificado_en' => 'datetime',
            'ultimo_acceso_en' => 'datetime',
            'password' => 'hashed',
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

    public function membresias(): HasMany
    {
        return $this->hasMany(Membresia::class, 'usuario_id');
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }
}
