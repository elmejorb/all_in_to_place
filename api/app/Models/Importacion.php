<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Importacion extends ModeloBase
{
    use PerteneceAEmpresa;

    protected $table = 'importacion';

    protected $fillable = [
        'tipo', 'archivo_nombre', 'estado', 'actualizar_existentes',
        'filas_total', 'filas_nuevas', 'filas_actualiza', 'filas_error',
        'filas', 'usuario_id', 'aplicada_en',
    ];

    protected function casts(): array
    {
        return [
            'actualizar_existentes' => 'boolean',
            'filas' => 'array',
            'aplicada_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function puedeAplicarse(): bool
    {
        return $this->estado === 'previsualizada' && ($this->filas_nuevas + $this->filas_actualiza) > 0;
    }
}
