<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class Suplidor extends ModeloBase
{
    use PerteneceAEmpresa, SoftDeletes;

    protected $table = 'suplidor';
    public const DELETED_AT = 'borrado_en';

    /** empresa_id no está aquí: lo pone el contexto, nunca el cliente (SEG-14). */
    protected $fillable = [
        'razon_social', 'nombre_comercial', 'numero_cliente', 'telefono',
        'email', 'vendedor', 'terminos_pago', 'notas', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** Columnas por las que se permite ordenar. Lista blanca contra inyección (SEG-29). */
    public const ORDENABLES = ['razon_social', 'numero_cliente', 'vendedor', 'created_at'];

    public function scopeBuscar(Builder $consulta, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $consulta;
        }

        $patron = '%'.mb_strtolower(trim($texto)).'%';

        return $consulta->where(function (Builder $q) use ($patron) {
            $q->whereRaw('lower(razon_social) like ?', [$patron])
                ->orWhereRaw('lower(coalesce(nombre_comercial, \'\')) like ?', [$patron])
                ->orWhereRaw('lower(coalesce(vendedor, \'\')) like ?', [$patron])
                ->orWhereRaw('lower(coalesce(numero_cliente, \'\')) like ?', [$patron]);
        });
    }

    public function nombre(): string
    {
        return $this->nombre_comercial ?: $this->razon_social;
    }
}
