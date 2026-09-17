<?php

namespace Tests\Unit;

use App\Domain\Cuadre;
use PHPUnit\Framework\TestCase;

/**
 * Los números de la hoja de cuadre, escritos antes que la pantalla (CAL-11).
 *
 * El ejemplo que se repite es el de un turno normal: se abre con 100 de
 * cambio, la registradora marca 1,250, de eso 400 fueron con tarjeta y 150 con
 * ATH Móvil, se apartan 100 para el cambio de mañana y se gastaron 80.
 */
class CuadreTest extends TestCase
{
    private const TURNO = [
        'efectivo_inicial' => 10000,
        'ventas_lectura' => 125000,
        'efectivo_cambio' => 10000,
        'tarjeta' => 40000,
        'ath_movil' => 15000,
    ];

    public function test_un_turno_normal_cuadra_hasta_el_deposito(): void
    {
        $r = Cuadre::calcular(self::TURNO, [5000, 3000]);

        $this->assertSame(135000, $r['venta_y_cambio']);   // 100 + 1250
        $this->assertSame(70000, $r['total_efectivo']);    // 1350 - 400 - 150 - 100
        $this->assertSame(8000, $r['gastos']);             // 50 + 30
        $this->assertSame(62000, $r['a_depositar']);       // 700 - 80
        $this->assertSame(125000, $r['total_ventas']);
    }

    public function test_el_fondo_de_cambio_no_cuenta_como_venta(): void
    {
        // Se abre con más dinero pero no se vendió más: el depósito sube, las
        // ventas no. Confundir las dos cosas infla el negocio.
        $conMasFondo = ['efectivo_inicial' => 50000] + self::TURNO;

        $normal = Cuadre::calcular(self::TURNO);
        $conFondo = Cuadre::calcular($conMasFondo);

        $this->assertSame($normal['total_ventas'], $conFondo['total_ventas']);
        $this->assertSame($normal['a_depositar'] + 40000, $conFondo['a_depositar']);
    }

    public function test_un_turno_solo_de_tarjeta_no_deja_efectivo_que_depositar(): void
    {
        $r = Cuadre::calcular([
            'efectivo_inicial' => 0,
            'ventas_lectura' => 50000,
            'efectivo_cambio' => 0,
            'tarjeta' => 50000,
            'ath_movil' => 0,
        ]);

        $this->assertSame(0, $r['total_efectivo']);
        $this->assertSame(0, $r['a_depositar']);
        $this->assertSame(50000, $r['total_ventas']);
    }

    public function test_una_hoja_en_blanco_da_ceros_y_no_revienta(): void
    {
        $r = Cuadre::calcular([]);

        $this->assertSame(0, $r['venta_y_cambio']);
        $this->assertSame(0, $r['total_efectivo']);
        $this->assertSame(0, $r['a_depositar']);
    }

    public function test_gastar_mas_de_lo_que_hay_deja_el_deposito_en_negativo(): void
    {
        // No se corrige a cero: un depósito negativo significa que falta
        // dinero en la gaveta y eso tiene que verse.
        $r = Cuadre::calcular(self::TURNO, [100000]);

        $this->assertSame(-30000, $r['a_depositar']);
    }

    public function test_los_gastos_se_suman_enteros(): void
    {
        $r = Cuadre::calcular(self::TURNO, [1, 2, 3, 4]);

        $this->assertSame(10, $r['gastos']);
    }

    // --- comparación con lo facturado ---------------------------------------

    public function test_dice_en_cuanto_difiere_lo_escrito_de_lo_facturado(): void
    {
        $r = Cuadre::comparar(
            ['ventas' => 125000, 'ath_movil' => 15000],
            ['ventas' => 126850, 'ath_movil' => 15000],
        );

        $this->assertSame(-1850, $r['ventas']['diferencia']);
        $this->assertFalse($r['ventas']['cuadra']);

        $this->assertSame(0, $r['ath_movil']['diferencia']);
        $this->assertTrue($r['ath_movil']['cuadra']);
    }

    public function test_lo_que_el_sistema_no_tiene_se_compara_contra_cero(): void
    {
        // Se cobró con tarjeta pero no se factur nada: la diferencia es todo.
        $r = Cuadre::comparar([], ['tarjeta' => 40000]);

        $this->assertSame(0, $r['tarjeta']['declarado']);
        $this->assertSame(-40000, $r['tarjeta']['diferencia']);
    }

    public function test_solo_compara_los_conceptos_que_el_sistema_conoce(): void
    {
        // El efectivo al comienzo no sale de ninguna factura: no se compara.
        $r = Cuadre::comparar(['efectivo_inicial' => 10000], ['ventas' => 0]);

        $this->assertArrayNotHasKey('efectivo_inicial', $r);
        $this->assertArrayHasKey('ventas', $r);
    }
}
