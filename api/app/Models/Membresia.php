<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que da acceso: usuario + empresa + rol (ROL-09).
 */
class Membresia extends ModeloBase
{
    protected $table = 'membresia';

    public const ROLES = ['propietario', 'administrador', 'gerente', 'empleado', 'contratista', 'contador'];
    public const PERFILES = ['mostrador', 'almacen'];   // ROL-08

    protected $fillable = ['empresa_id', 'usuario_id', 'rol', 'perfil', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
