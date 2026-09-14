<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categoria extends ModeloBase
{
    use PerteneceAEmpresa, SoftDeletes;

    protected $table = 'categoria';
    public const DELETED_AT = 'borrado_en';

    /**
     * Paleta cerrada de etiquetas. Los tonos se resuelven en el sistema de
     * diseño, así que funcionan igual en tema claro y oscuro (IU-03).
     */
    public const COLORES = ['pizarra', 'verde', 'azul', 'morado', 'ambar', 'rojo', 'rosa', 'turquesa'];

    protected $fillable = ['nombre', 'descripcion', 'color', 'orden'];

    protected function casts(): array
    {
        return ['orden' => 'integer'];
    }

    public const ORDENABLES = ['nombre', 'created_at'];

    public function scopeBuscar(Builder $consulta, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $consulta;
        }

        $patron = '%'.mb_strtolower(trim($texto)).'%';

        return $consulta->where(function (Builder $q) use ($patron) {
            $q->whereRaw('lower(nombre) like ?', [$patron])
                ->orWhereRaw('lower(coalesce(descripcion, \'\')) like ?', [$patron]);
        });
    }
}
