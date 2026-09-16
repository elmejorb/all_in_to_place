<?php

namespace App\Domain;

/**
 * Reglas de precio y margen. Sin framework, sin base de datos (ARQ-18).
 *
 * Todo el dinero viaja en centavos enteros: un producto de $5.99 son 599. En
 * este proyecto no existe un importe en coma flotante (ARQ-09).
 */
final class Precio
{
    /**
     * Margen sobre el precio de venta, en porcentaje.
     *
     * Se calcula sobre el precio, no sobre el costo: es la fracción de cada
     * dólar vendido que queda. Devuelve null cuando no se puede calcular
     * (sin precio, o precio cero).
     */
    public static function margen(int $costoCentavos, int $precioCentavos): ?float
    {
        if ($precioCentavos <= 0) {
            return null;
        }

        return round((($precioCentavos - $costoCentavos) / $precioCentavos) * 100, 2);
    }

    /** Ganancia por unidad, en centavos. Puede ser negativa. */
    public static function ganancia(int $costoCentavos, int $precioCentavos): int
    {
        return $precioCentavos - $costoCentavos;
    }

    /** Vender por debajo del costo casi siempre es un error de captura (PRO-02). */
    public static function vendeBajoCosto(int $costoCentavos, int $precioCentavos): bool
    {
        return $precioCentavos > 0 && $precioCentavos < $costoCentavos;
    }

    /**
     * Convierte lo que se escribe en pantalla ("5.99", "5,99", "  12 ") a
     * centavos enteros. Devuelve null si no es un número válido.
     */
    public static function aCentavos(string|int|float|null $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $texto = str_replace([' ', ','], ['', '.'], (string) $valor);

        if (! is_numeric($texto)) {
            return null;
        }

        // round() antes de (int) para que 0.1 + 0.2 no se convierta en 29 centavos.
        return (int) round(((float) $texto) * 100);
    }

    /**
     * Como aCentavos, pero para dinero que no puede quedar en nada: si el texto
     * no es un número, revienta en vez de guardar un cero silencioso.
     */
    public static function monto(string|int|float|null $valor): int
    {
        $centavos = self::aCentavos($valor);

        if ($centavos === null) {
            throw new \InvalidArgumentException('Importe inválido: '.var_export($valor, true));
        }

        return $centavos;
    }

    /** Centavos a texto con dos decimales, para mostrar y para exportar. */
    public static function aTexto(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }

    /**
     * Las tasas de impuesto se guardan en milésimas de punto porcentual:
     * 11.5% es 11500. Así no se pierde precisión al sumar renglones.
     */
    public static function tasaAMilesimas(string|int|float|null $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $texto = str_replace([' ', ',', '%'], ['', '.', ''], (string) $valor);

        if (! is_numeric($texto)) {
            return null;
        }

        return (int) round(((float) $texto) * 1000);
    }

    public static function tasaATexto(int $milesimas): string
    {
        return rtrim(rtrim(number_format($milesimas / 1000, 3, '.', ''), '0'), '.') ?: '0';
    }
}
