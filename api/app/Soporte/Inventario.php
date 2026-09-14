<?php

namespace App\Soporte;

use App\Models\MovimientoInventario;
use App\Models\Producto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * El único camino por el que cambia una existencia (INV-01, INV-02).
 *
 * Nadie escribe `producto.existencia` a mano: se registra un movimiento y el
 * registrador actualiza la caché dentro de la misma transacción, con la fila
 * bloqueada para que dos cajeros a la vez no se pisen.
 */
final class Inventario
{
    public static function registrar(
        Producto $producto,
        string $tipo,
        float $cantidad,
        ?string $motivo = null,
        ?string $comentario = null,
        ?int $costoUnitarioCentavos = null,
        ?string $referenciaTipo = null,
        ?int $referenciaId = null,
    ): MovimientoInventario {
        if ($producto->es_servicio) {
            throw new \DomainException('Un servicio no lleva inventario.');
        }

        if (! in_array($tipo, MovimientoInventario::TIPOS, true)) {
            throw new \InvalidArgumentException("Tipo de movimiento desconocido: {$tipo}");
        }

        return DB::transaction(function () use ($producto, $tipo, $cantidad, $motivo, $comentario, $costoUnitarioCentavos, $referenciaTipo, $referenciaId) {
            // Bloquea la fila: la existencia se lee y se escribe sin que nadie
            // se cuele en medio.
            $fresco = Producto::query()->lockForUpdate()->findOrFail($producto->id);

            $resultante = round((float) $fresco->existencia + $cantidad, 3);

            $movimiento = MovimientoInventario::create([
                'producto_id' => $fresco->id,
                'tipo' => $tipo,
                'cantidad' => $cantidad,
                'existencia_resultante' => $resultante,
                'costo_unitario_centavos' => $costoUnitarioCentavos,
                'motivo' => $motivo,
                'comentario' => $comentario,
                'usuario_id' => Auth::guard('empresa')->id(),
                'referencia_tipo' => $referenciaTipo,
                'referencia_id' => $referenciaId,
            ]);

            // Caché, no verdad: siempre recalculable con recalcular().
            $fresco->forceFill(['existencia' => $resultante])->save();
            $producto->setAttribute('existencia', $resultante);

            return $movimiento;
        });
    }

    /** Lleva la existencia a una cantidad concreta. Es lo que hace un conteo. */
    public static function ajustarA(Producto $producto, float $cantidadContada, string $motivo, ?string $comentario = null): ?MovimientoInventario
    {
        $diferencia = round($cantidadContada - (float) $producto->existencia, 3);

        if (abs($diferencia) < 0.0005) {
            return null;   // no hay nada que registrar
        }

        return self::registrar($producto, 'ajuste', $diferencia, $motivo, $comentario);
    }

    /**
     * Recalcula la caché desde los movimientos. Es la prueba de que la columna
     * `existencia` no es la verdad, sino un resumen de ella.
     */
    public static function recalcular(Producto $producto): float
    {
        $suma = (float) MovimientoInventario::query()
            ->where('producto_id', $producto->id)
            ->sum('cantidad');

        $producto->forceFill(['existencia' => $suma])->save();

        return $suma;
    }
}
