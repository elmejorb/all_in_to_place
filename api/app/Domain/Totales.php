<?php

namespace App\Domain;

/**
 * El cálculo de un documento (FAC-04, FAC-05). Sin framework ni base de datos.
 *
 * Reglas, en este orden:
 *
 *  1. Cada renglón: bruto = cantidad x precio, redondeado a centavos.
 *  2. Se resta el descuento del renglón.
 *  3. El descuento global se reparte entre los renglones en proporción a lo que
 *     pesa cada uno. Así el impuesto se calcula sobre la base que de verdad se
 *     cobra, y no sobre una base inflada.
 *  4. El impuesto se calcula por renglón sobre esa base neta.
 *  5. El total es la suma exacta de sus partes. Los centavos que sobran al
 *     repartir se le dan al renglón mayor, para que nunca falte ni sobre uno.
 *
 * Todo en centavos enteros. Las tasas en milésimas de punto: 11.5% es 11500.
 */
final class Totales
{
    /**
     * @param  list<array{cantidad: float, precio_centavos: int, descuento_tipo?: ?string, descuento_valor?: string|float|int|null, impuesto_milesimas?: int, exento?: bool}>  $renglones
     * @param  array{tipo?: ?string, valor?: string|float|int|null}  $descuentoGlobal
     * @param  list<array{nombre: string, milesimas: int}>  $desgloseImpuesto  Cómo se reparte la tasa (FAC-04)
     * @return array{renglones: list<array<string, mixed>>, subtotal: int, descuento_renglones: int, descuento_global: int, base: int, impuesto: int, total: int, desglose: list<array{nombre: string, milesimas: int, monto: int}>}
     */
    public static function calcular(array $renglones, array $descuentoGlobal = [], array $desgloseImpuesto = []): array
    {
        $calculados = [];
        $subtotal = 0;
        $descuentoRenglones = 0;

        foreach ($renglones as $renglon) {
            $cantidad = (float) ($renglon['cantidad'] ?? 0);
            $precio = (int) ($renglon['precio_centavos'] ?? 0);

            $bruto = (int) round($cantidad * $precio);
            $descuento = self::descuento($bruto, $renglon['descuento_tipo'] ?? null, $renglon['descuento_valor'] ?? null);
            $descuento = min($descuento, $bruto);   // nadie regala más de lo que vale

            $calculados[] = [
                'cantidad' => $cantidad,
                'precio_centavos' => $precio,
                'bruto_centavos' => $bruto,
                'descuento_centavos' => $descuento,
                'impuesto_milesimas' => ($renglon['exento'] ?? false) ? 0 : (int) ($renglon['impuesto_milesimas'] ?? 0),
                'exento' => (bool) ($renglon['exento'] ?? false),
            ];

            $subtotal += $bruto;
            $descuentoRenglones += $descuento;
        }

        $baseAntesDeGlobal = $subtotal - $descuentoRenglones;

        // --- descuento global, repartido en proporción -----------------------
        $global = self::descuento($baseAntesDeGlobal, $descuentoGlobal['tipo'] ?? null, $descuentoGlobal['valor'] ?? null);
        $global = min($global, $baseAntesDeGlobal);

        $repartido = self::repartir($global, array_map(
            fn (array $r) => $r['bruto_centavos'] - $r['descuento_centavos'],
            $calculados,
        ));

        // --- impuesto por renglón sobre la base neta -------------------------
        $base = 0;
        $impuesto = 0;
        $impuestoPorTasa = [];

        foreach ($calculados as $i => $renglon) {
            $baseRenglon = $renglon['bruto_centavos'] - $renglon['descuento_centavos'] - $repartido[$i];
            $impuestoRenglon = (int) round($baseRenglon * $renglon['impuesto_milesimas'] / 100000);

            $calculados[$i]['descuento_global_centavos'] = $repartido[$i];
            $calculados[$i]['base_centavos'] = $baseRenglon;
            $calculados[$i]['impuesto_centavos'] = $impuestoRenglon;
            $calculados[$i]['total_centavos'] = $baseRenglon + $impuestoRenglon;

            $base += $baseRenglon;
            $impuesto += $impuestoRenglon;

            if ($renglon['impuesto_milesimas'] > 0) {
                $impuestoPorTasa[$renglon['impuesto_milesimas']] =
                    ($impuestoPorTasa[$renglon['impuesto_milesimas']] ?? 0) + $impuestoRenglon;
            }
        }

        return [
            'renglones' => $calculados,
            'subtotal' => $subtotal,
            'descuento_renglones' => $descuentoRenglones,
            'descuento_global' => $global,
            'base' => $base,
            'impuesto' => $impuesto,
            'total' => $base + $impuesto,
            'desglose' => self::desglosar($impuestoPorTasa, $desgloseImpuesto),
        ];
    }

    /**
     * Reparte el impuesto cobrado entre sus componentes (FAC-04).
     *
     * El desglose es configuración de la empresa, no código: en Puerto Rico son
     * estatal y municipal; en otro país puede ser uno solo con otro nombre.
     *
     * @param  array<int, int>  $impuestoPorTasa  milésimas => centavos cobrados
     * @param  list<array{nombre: string, milesimas: int}>  $componentes
     * @return list<array{nombre: string, milesimas: int, monto: int}>
     */
    private static function desglosar(array $impuestoPorTasa, array $componentes): array
    {
        $totalCobrado = array_sum($impuestoPorTasa);

        if ($totalCobrado === 0) {
            return [];
        }

        if ($componentes === []) {
            return [['nombre' => 'Impuesto', 'milesimas' => 0, 'monto' => $totalCobrado]];
        }

        $sumaComponentes = array_sum(array_column($componentes, 'milesimas'));

        if ($sumaComponentes <= 0) {
            return [['nombre' => 'Impuesto', 'milesimas' => 0, 'monto' => $totalCobrado]];
        }

        $montos = self::repartir($totalCobrado, array_column($componentes, 'milesimas'));

        return array_values(array_map(
            fn (array $componente, int $monto) => [
                'nombre' => $componente['nombre'],
                'milesimas' => $componente['milesimas'],
                'monto' => $monto,
            ],
            $componentes,
            $montos,
        ));
    }

    /**
     * Reparte un monto en proporción a unos pesos, sin perder ni inventar
     * centavos: lo que sobra al redondear se le da al peso mayor.
     *
     * @param  list<int|float>  $pesos
     * @return list<int>
     */
    private static function repartir(int $monto, array $pesos): array
    {
        $cantidad = count($pesos);

        if ($cantidad === 0 || $monto === 0) {
            return array_fill(0, max($cantidad, 0), 0);
        }

        $suma = array_sum($pesos);

        if ($suma <= 0) {
            return array_fill(0, $cantidad, 0);
        }

        $partes = [];
        foreach ($pesos as $peso) {
            $partes[] = (int) floor($monto * $peso / $suma);
        }

        $sobrante = $monto - array_sum($partes);

        if ($sobrante !== 0) {
            $mayor = array_keys($pesos, max($pesos), true)[0];
            $partes[$mayor] += $sobrante;
        }

        return $partes;
    }

    /** Un descuento puede venir en dinero o en porcentaje. */
    private static function descuento(int $base, ?string $tipo, string|float|int|null $valor): int
    {
        if ($valor === null || $valor === '' || $base <= 0) {
            return 0;
        }

        if ($tipo === 'porcentaje') {
            $porcentaje = (float) str_replace(',', '.', (string) $valor);

            if ($porcentaje <= 0) {
                return 0;
            }

            return (int) round($base * min($porcentaje, 100) / 100);
        }

        return max(0, Precio::aCentavos($valor) ?? 0);
    }
}
