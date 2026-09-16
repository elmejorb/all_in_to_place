<?php

namespace App\Soporte;

/**
 * Lo que falta para poder vender, con nombre y cantidades (FAC-08).
 *
 * No es un error técnico: es una pregunta para quien está en la caja, que puede
 * decidir vender igual o no vender.
 */
class SinExistencia extends \DomainException
{
    /** @param  list<array{producto: string, pedido: float, disponible: float}>  $faltantes */
    public function __construct(public readonly array $faltantes)
    {
        parent::__construct('No hay existencia suficiente para vender.');
    }
}
