<?php

namespace Tests\Unit;

use App\Domain\Cuadre;
use PHPUnit\Framework\TestCase;

/**
 * Los números de la hoja de cuadre (CAL-11).
 *
 * El caso de referencia son las cifras que Luis metió en el sistema actual el
 * 17 de septiembre de 2026 para enseñarme los cálculos. Mientras esta prueba
 * pase, una hoja hecha aquí da exactamente lo mismo que la de allá; si alguien
 * cambia una fórmula, se entera aquí y no en la caja.
 */
class CuadreTest extends TestCase
{
    /** Efectivo 5,000 · lectura 150,000 · tarjeta 100,000 · ATH Móvil 154,000 · cambio 12. */
    private const REFERENCIA = [
        'efectivo_inicial' => 500000,
        'ventas_lectura' => 15000000,
        'efectivo_cambio' => 1200,
        'tarjeta' => 10000000,
        'ath_movil' => 15400000,
    ];

    public function test_reproduce_la_hoja_del_sistema_actual(): void
    {
        $r = Cuadre::calcular(self::REFERENCIA, [15000]);   // un gasto de 150

        $this->assertSame(15500000, $r['venta_y_cambio']);   // 155,000.00
        $this->assertSame(15498800, $r['total_efectivo']);   // 154,988.00
        $this->assertSame(15000, $r['gastos']);              //     150.00
        $this->assertSame(15483800, $r['a_depositar']);      // 154,838.00
        $this->assertSame(40883800, $r['total_ventas']);     // 408,838.00
    }

    public function test_la_tarjeta_no_sale_de_la_gaveta(): void
    {
        // Lo cobrado con tarjeta y con ATH Móvil no toca el efectivo: cambiarlos
        // no mueve ni el total de efectivo ni el depósito, solo el total vendido.
        $sinTarjeta = ['tarjeta' => 0, 'ath_movil' => 0] + self::REFERENCIA;

        $con = Cuadre::calcular(self::REFERENCIA);
        $sin = Cuadre::calcular($sinTarjeta);

        $this->assertSame($con['total_efectivo'], $sin['total_efectivo']);
        $this->assertSame($con['a_depositar'], $sin['a_depositar']);
        $this->assertSame($con['total_ventas'] - 25400000, $sin['total_ventas']);
    }

    public function test_el_cambio_apartado_sale_de_la_gaveta_pero_no_de_las_ventas(): void
    {
        $sinApartar = ['efectivo_cambio' => 0] + self::REFERENCIA;

        $normal = Cuadre::calcular(self::REFERENCIA);
        $todo = Cuadre::calcular($sinApartar);

        $this->assertSame($normal['total_efectivo'] + 1200, $todo['total_efectivo']);
        $this->assertSame($normal['a_depositar'] + 1200, $todo['a_depositar']);
    }

    public function test_los_gastos_se_suman_y_bajan_el_deposito(): void
    {
        $r = Cuadre::calcular(self::REFERENCIA, [15000, 2500, 1]);

        $this->assertSame(17501, $r['gastos']);
        $this->assertSame(15498800 - 17501, $r['a_depositar']);
    }

    public function test_una_hoja_en_blanco_da_ceros_y_no_revienta(): void
    {
        $r = Cuadre::calcular([]);

        $this->assertSame(0, $r['venta_y_cambio']);
        $this->assertSame(0, $r['total_efectivo']);
        $this->assertSame(0, $r['a_depositar']);
        $this->assertSame(0, $r['total_ventas']);
    }

    public function test_gastar_mas_de_lo_que_hay_deja_el_deposito_en_negativo(): void
    {
        // No se corrige a cero: un depósito negativo significa que falta dinero
        // en la gaveta y eso tiene que verse.
        $r = Cuadre::calcular([
            'efectivo_inicial' => 0,
            'ventas_lectura' => 10000,
            'efectivo_cambio' => 0,
            'tarjeta' => 0,
            'ath_movil' => 0,
        ], [50000]);

        $this->assertSame(-40000, $r['a_depositar']);
    }

    public function test_un_turno_solo_de_tarjeta_no_deja_efectivo_que_depositar(): void
    {
        $r = Cuadre::calcular([
            'efectivo_inicial' => 0,
            'ventas_lectura' => 0,
            'efectivo_cambio' => 0,
            'tarjeta' => 50000,
            'ath_movil' => 0,
        ]);

        $this->assertSame(0, $r['total_efectivo']);
        $this->assertSame(0, $r['a_depositar']);
        $this->assertSame(50000, $r['total_ventas']);
    }

    // --- comparación con lo facturado ---------------------------------------

    public function test_dice_en_cuanto_difiere_lo_escrito_de_lo_facturado(): void
    {
        $r = Cuadre::comparar(
            ['efectivo' => 15000000, 'ath_movil' => 15400000],
            ['efectivo' => 14990000, 'ath_movil' => 15400000],
        );

        $this->assertSame(10000, $r['efectivo']['diferencia']);
        $this->assertFalse($r['efectivo']['cuadra']);

        $this->assertSame(0, $r['ath_movil']['diferencia']);
        $this->assertTrue($r['ath_movil']['cuadra']);
    }

    public function test_lo_que_el_sistema_no_tiene_se_compara_contra_cero(): void
    {
        // Se escribió que entraron 400 con tarjeta pero no se facturó ninguna.
        $r = Cuadre::comparar([], ['tarjeta' => 40000]);

        $this->assertSame(0, $r['tarjeta']['declarado']);
        $this->assertSame(-40000, $r['tarjeta']['diferencia']);
    }

    public function test_solo_compara_los_conceptos_que_el_sistema_conoce(): void
    {
        // El efectivo al comienzo no sale de ninguna factura: no se compara.
        $r = Cuadre::comparar(['efectivo_inicial' => 10000], ['efectivo' => 0]);

        $this->assertArrayNotHasKey('efectivo_inicial', $r);
        $this->assertArrayHasKey('efectivo', $r);
    }
}
