<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La cuenta. País, moneda y zona horaria son de la empresa, no de la
 * plataforma (ADM-22, ARQ-10).
 */
class Empresa extends ModeloBase
{
    use SoftDeletes;

    protected $table = 'empresa';
    public const DELETED_AT = 'borrado_en';

    public const ESTADOS = ['prueba', 'activa', 'morosa', 'suspendida', 'cancelada'];

    protected $fillable = [
        'nombre_legal', 'nombre_comercial', 'registro_comerciante', 'telefono', 'email',
        'direccion_fisica', 'direccion_postal', 'pais', 'moneda', 'zona_horaria',
        'idioma', 'estado', 'logo_ruta',
    ];

    public function membresias(): HasMany
    {
        return $this->hasMany(Membresia::class, 'empresa_id');
    }

    public function nombre(): string
    {
        return $this->nombre_comercial ?: $this->nombre_legal;
    }

    /** Una empresa suspendida entra en solo lectura, no desaparece (ADM-03). */
    public function permiteEscritura(): bool
    {
        return in_array($this->estado, ['prueba', 'activa', 'morosa'], true);
    }
}
