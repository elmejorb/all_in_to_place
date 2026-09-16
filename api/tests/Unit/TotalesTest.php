<?php

namespace Tests\Unit;

use App\Domain\Totales;
use PHPUnit\Framework\TestCase;

/**
 * Casos numéricos escritos antes de la implementación (CAL-11).
 *
 * Esto es lo que no puede estar mal: si el total de una factura no cuadra con
 * la suma de sus partes, el negocio cobra de menos o de más todos los días.
 */
class TotalesTest extends TestCase
{
    /** Desglose de Puerto Rico: 10.5% estatal más 1% municipal. */
    private const DESGLOSE_PR = [
        ['nombre' => 'Estatal', 'milesimas' => 10500],
        ['nombre' => 'Municipal', 'milesimas' => 1000],
    ];

    public function test_un_renglon_simple(): void
    {
        $r = Totales::calcular([
            ['cantidad' => 2, 'precio_centavos' => 950, 'impuesto_milesimas' => 11500],
        ]);

        $this->assertSame(1900, $r['subtotal']);
        $this->assertSame(1900, $r['base']);
        $this->assertSame(219, $r['impuesto']);      // 1900 x 11.5% = 218.5 -> 219
        $this->assertSame(2119, $r['total']);
    }

    public function test_el_total_es_siempre_la_suma_de_sus_partes(): void
    {
        $r = Totales::calcular([
            ['cantidad' => 3, 'precio_centavos' => 599, 'impuesto_milesimas' => 11500],
            ['cantidad' => 1, 'precio_centavos' => 6000, 'impuesto_milesimas' => 11500],
            ['cantidad' => 2.5, 'precio_centavos' => 250, 'impuesto_milesimas' => 7000],
        ], ['tipo' => 'porcentaje', 'valor' => 10]);

        $this->assertSame($r['base'] + $r['impuesto'], $r['total']);
        $this->assertSame($r['subtotal'] - $r['descuento_renglones'] - $r['descuento_global'], $r['base']);

        // Y renglón por renglón.
        $sumaBases = array_sum(array_column($r['renglones'], 'base_centavos'));
        $sumaImpuestos = array_sum(array_column($r['renglones'], 'impuesto_centavos'));
        $this->assertSame($r['base'], $sumaBases);
        $this->assertSame($r['impuesto'], $sumaImpuestos);
    }

    public function test_cantidad_fraccionada(): void
    {
        // Media libra de torta a 60.00 la libra.
        $r = Totales::calcular([
            ['cantidad' => 0.5, 'precio_centavos' => 6000, 'impuesto_milesimas' => 11500],
        ]);

        $this->assertSame(3000, $r['subtotal']);
        $this->assertSame(345, $r['impuesto']);
        $this->assertSame(3345, $r['total']);
    }

    public function test_descuento_por_renglon_en_dinero_y_en_porcentaje(): void
    {
        $enDinero = Totales::calcular([
            ['cantidad' => 1, 'precio_centavos' => 1000, 'descuento_tipo' => 'monto', 'descuento_valor' => '2.00', 'impuesto_milesimas' => 11500],
        ]);

        $this->assertSame(200, $enDinero['descuento_renglones']);
        $this->assertSame(800, $enDinero['base']);
        $this->assertSame(92, $enDinero['impuesto']);          // sobre la base neta, no sobre 1000

        $enPorcentaje = Totales::calcular([
            ['cantidad' => 1, 'precio_centavos' => 1000, 'descuento_tipo' => 'porcentaje', 'descuento_valor' => 20, 'impuesto_milesimas' => 11500],
        ]);

        $this->assertSame(200, $enPorcentaje['descuento_renglones']);
        $this->assertSame(800, $enPorcentaje['base']);
    }

    public function test_el_impuesto_se_calcula_despues_del_descuento(): void
    {
        // FAC-05: si se calculara antes, el cliente pagaría impuesto por un
        // dinero que no está pagando.
        $conDescuento = Totales::calcular([
            ['cantidad' => 1, 'precio_centavos' => 10000, 'descuento_tipo' => 'porcentaje', 'descuento_valor' => 50, 'impuesto_milesimas' => 11500],
        ]);

        $sinDescuento = Totales::calcular([
            ['cantidad' => 1, 'precio_centavos' => 5000, 'impuesto_milesimas' => 11500],
        ]);

        $this->assertSame($sinDescuento['impuesto'], $conDescuento['impuesto']);
        $this->assertSame($sinDescuento['total'], $conDescuento['total']);
    }

    public function test_el_descuento_global_se_reparte_entre_los_renglones(): void
    {
        $r = Totales::calcular([
            ['cantidad' => 1, 'precio_centavos' => 3000, 'impuesto_milesimas' => 11500],
            ['cantidad' => 1, 'precio_centavos' => 1000, 'impuesto_milesimas' => 11500],
        ], ['tipo' => 'monto', 'valor' => '4.00']);

        // 400 centavos repartidos 3:1.
        $this->assertSame(300, $r['renglones'][0]['descuento_global_centavos']);
        $this->assertSame(100, $r['renglones'][1]['descuento_global_centavos']);
        $this->assertSame(3600, $r['base']);

        // 311 + 104. Sobre el total serían 414: el redondeo por renglón puede
        // dar un centavo de diferencia, y es a propósito. Cada renglón tiene que
        // cuadrar solo en la factura impresa, que es lo que manda FAC-05.
        $this->assertSame(415, $r['impuesto']);
    }

    public function test_al_repartir_no_se_pierde_ni_se_inventa_un_centavo(): void
    {
        // Tres renglones iguales y un descuento que no divide exacto.
        $r = Totales::calcular([
            ['cantidad' => 1, 'precio_centavos' => 1000, 'impuesto_milesimas' => 11500],
            ['cantidad' => 1, 'precio_centavos' => 1000, 'impuesto_milesimas' => 11500],
            ['cantidad' => 1, 'precio_centavos' => 1000, 'impuesto_milesimas' => 11500],
        ], ['tipo' => 'monto', 'valor' => '0.10']);

        $repartido = array_column($r['renglones'], 'descuento_global_centavos');

        // Lo que importa no es a quién le toca el centavo suelto, sino que se
        // reparta entero y a un solo renglón.
        $this->assertSame(10, array_sum($repartido));
        $this->assertSame([3, 3], array_values(array_diff($repartido, [4])));
        $this->assertContains(4, $repartido);
        $this->assertSame(2990, $r['base']);
    }

    public function test_renglones_con_tasas_distintas(): void
    {
        // Pan al 11.5% y café al 7% en la misma factura.
        $r = Totales::calcular([
            ['cantidad' => 2, 'precio_centavos' => 950, 'impuesto_milesimas' => 11500],
            ['cantidad' => 1, 'precio_centavos' => 250, 'impuesto_milesimas' => 7000],
        ]);

        $this->assertSame(219, $r['renglones'][0]['impuesto_centavos']);
        $this->assertSame(18, $r['renglones'][1]['impuesto_centavos']);   // 250 x 7% = 17.5 -> 18
        $this->assertSame(237, $r['impuesto']);
    }

    public function test_un_renglon_exento_no_paga_impuesto(): void
    {
        $r = Totales::calcular([
            ['cantidad' => 1, 'precio_centavos' => 1000, 'impuesto_milesimas' => 11500, 'exento' => true],
            ['cantidad' => 1, 'precio_centavos' => 1000, 'impuesto_milesimas' => 11500],
        ]);

        $this->assertSame(0, $r['renglones'][0]['impuesto_centavos']);
        $this->assertSame(115, $r['renglones'][1]['impuesto_centavos']);
        $this->assertSame(115, $r['impuesto']);
        $this->assertSame(2115, $r['total']);
    }

    public function test_desglosa_el_impuesto_en_sus_componentes(): void
    {
        // FAC-04: en Puerto Rico hay que declarar estatal y municipal aparte.
        $r = Totales::calcular(
            [['cantidad' => 1, 'precio_centavos' => 10000, 'impuesto_milesimas' => 11500]],
            [],
            self::DESGLOSE_PR,
        );

        $this->assertSame(1150, $r['impuesto']);
        $this->assertCount(2, $r['desglose']);
        $this->assertSame('Estatal', $r['desglose'][0]['nombre']);
        $this->assertSame(1050, $r['desglose'][0]['monto']);
        $this->assertSame('Municipal', $r['desglose'][1]['nombre']);
        $this->assertSame(100, $r['desglose'][1]['monto']);

        // El desglose suma exactamente el impuesto cobrado.
        $this->assertSame($r['impuesto'], array_sum(array_column($r['desglose'], 'monto')));
    }

    public function test_el_desglose_cuadra_aunque_el_reparto_no_sea_exacto(): void
    {
        $r = Totales::calcular(
            [['cantidad' => 3, 'precio_centavos' => 333, 'impuesto_milesimas' => 11500]],
            [],
            self::DESGLOSE_PR,
        );

        $this->assertSame($r['impuesto'], array_sum(array_column($r['desglose'], 'monto')));
    }

    public function test_sin_desglose_configurado_el_impuesto_va_entero(): void
    {
        // Es lo que pasaría en un país con un solo impuesto.
        $r = Totales::calcular([['cantidad' => 1, 'precio_centavos' => 10000, 'impuesto_milesimas' => 19000]]);

        $this->assertCount(1, $r['desglose']);
        $this->assertSame(1900, $r['desglose'][0]['monto']);
    }

    public function test_una_factura_sin_impuesto_no_trae_desglose(): void
    {
        $r = Totales::calcular([['cantidad' => 1, 'precio_centavos' => 1000]], [], self::DESGLOSE_PR);

        $this->assertSame(0, $r['impuesto']);
        $this->assertSame([], $r['desglose']);
        $this->assertSame(1000, $r['total']);
    }

    // --- bordes -----------------------------------------------------------

    public function test_una_factura_vacia_da_cero(): void
    {
        $r = Totales::calcular([]);

        $this->assertSame(0, $r['subtotal']);
        $this->assertSame(0, $r['total']);
        $this->assertSame([], $r['desglose']);
    }

    public function test_un_descuento_mayor_que_el_renglon_no_deja_totales_negativos(): void
    {
        $r = Totales::calcular([
            ['cantidad' => 1, 'precio_centavos' => 1000, 'descuento_tipo' => 'monto', 'descuento_valor' => '50.00', 'impuesto_milesimas' => 11500],
        ]);

        $this->assertSame(1000, $r['descuento_renglones']);
        $this->assertSame(0, $r['base']);
        $this->assertSame(0, $r['total']);
    }

    public function test_un_porcentaje_mayor_que_cien_se_queda_en_cien(): void
    {
        $r = Totales::calcular([
            ['cantidad' => 1, 'precio_centavos' => 1000, 'descuento_tipo' => 'porcentaje', 'descuento_valor' => 150],
        ]);

        $this->assertSame(1000, $r['descuento_renglones']);
        $this->assertSame(0, $r['total']);
    }

    public function test_una_factura_larga_sigue_cuadrando(): void
    {
        // Cincuenta renglones con precios feos y descuento global: el sitio
        // donde los redondeos se acumulan.
        $renglones = [];
        for ($i = 1; $i <= 50; $i++) {
            $renglones[] = [
                'cantidad' => $i % 7 === 0 ? 0.25 : $i % 3,
                'precio_centavos' => 99 + $i * 37,
                'impuesto_milesimas' => $i % 2 === 0 ? 11500 : 7000,
            ];
        }

        $r = Totales::calcular($renglones, ['tipo' => 'porcentaje', 'valor' => '7.5'], self::DESGLOSE_PR);

        $this->assertSame($r['base'] + $r['impuesto'], $r['total']);
        $this->assertSame($r['base'], array_sum(array_column($r['renglones'], 'base_centavos')));
        $this->assertSame($r['impuesto'], array_sum(array_column($r['renglones'], 'impuesto_centavos')));
        $this->assertSame($r['impuesto'], array_sum(array_column($r['desglose'], 'monto')));
        $this->assertSame($r['descuento_global'], array_sum(array_column($r['renglones'], 'descuento_global_centavos')));
    }
}
