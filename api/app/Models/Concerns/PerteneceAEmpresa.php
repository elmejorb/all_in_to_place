<?php

namespace App\Models\Concerns;

use App\Soporte\ContextoRls;
use Illuminate\Database\Eloquent\Builder;

/**
 * Todo modelo de negocio lleva empresa_id y nunca lo recibe del cliente.
 *
 * Son dos capas y las dos hacen falta: este scope evita el error honesto de
 * olvidar el filtro, y la política RLS de PostgreSQL evita el resto —una
 * consulta cruda, un scope removido a mano— porque la base no entrega filas de
 * otra empresa aunque se las pidan (ARQ-04).
 */
trait PerteneceAEmpresa
{
    public static function bootPerteneceAEmpresa(): void
    {
        static::addGlobalScope('empresa', function (Builder $consulta) {
            $empresaId = ContextoRls::empresaActual();

            // Sin contexto no se devuelve nada: negar por defecto (SEG-11).
            $consulta->where($consulta->getModel()->getTable().'.empresa_id', $empresaId ?? 0);
        });

        static::creating(function ($modelo) {
            if (empty($modelo->empresa_id)) {
                $modelo->empresa_id = ContextoRls::empresaActual();
            }
        });
    }
}
