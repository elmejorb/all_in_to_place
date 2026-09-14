<?php

namespace Tests\Unit;

use App\Domain\Precio;
use PHPUnit\Framework\TestCase;

/**
 * Casos numéricos escritos antes de la implementación (CAL-11).
 * Corren sin base de datos: son milisegundos.
 */
class PrecioTest extends TestCase
{
    public static function margenes(): array
    {
        return [
            // costo, precio, margen esperado
            'mitad de margen' => [500, 1000, 50.0],
            'pan de leche' => [599, 1200, 50.08],
            'torta media libra' => [6000, 9500, 36.84],
            'sin ganancia' => [1000, 1000, 0.0],
            'vendido bajo costo' => [1200, 1000, -20.0],
            'costo cero' => [0, 1000, 100.0],
        ];
    }

    /** @dataProvider margenes */
    public function test_calcula_el_margen_sobre_el_precio(int $costo, int $precio, float $esperado): void
    {
        $this->assertSame($esperado, Precio::margen($costo, $precio));
    }

    public function test_sin_precio_no_hay_margen(): void
    {
        $this->assertNull(Precio::margen(500, 0));
        $this->assertNull(Precio::margen(0, 0));
    }

    public function test_avisa_cuando_se_vende_bajo_costo(): void
    {
        $this->assertTrue(Precio::vendeBajoCosto(1200, 1000));
        $this->assertFalse(Precio::vendeBajoCosto(1000, 1000));
        $this->assertFalse(Precio::vendeBajoCosto(1000, 1500));
        // Sin precio todavía no hay nada que avisar.
        $this->assertFalse(Precio::vendeBajoCosto(1000, 0));
    }

    public function test_ganancia_por_unidad(): void
    {
        $this->assertSame(601, Precio::ganancia(599, 1200));
        $this->assertSame(-200, Precio::ganancia(1200, 1000));
    }

    public static function importes(): array
    {
        return [
            ['5.99', 599],
            ['5,99', 599],     // coma decimal, que es como se escribe aquí también
            ['  12 ', 1200],
            ['0.1', 10],
            ['0.05', 5],
            ['1999.99', 199999],
            ['0', 0],
            ['no es número', null],
            ['', null],
            [null, null],
        ];
    }

    /** @dataProvider importes */
    public function test_convierte_lo_escrito_a_centavos(string|int|float|null $escrito, ?int $esperado): void
    {
        $this->assertSame($esperado, Precio::aCentavos($escrito));
    }

    public function test_no_pierde_un_centavo_al_convertir(): void
    {
        // El clásico: 0.1 + 0.2 en coma flotante da 0.30000000000000004.
        $this->assertSame(30, Precio::aCentavos(0.1 + 0.2));

        // Y de vuelta.
        $this->assertSame('0.30', Precio::aTexto(30));
        $this->assertSame('1999.99', Precio::aTexto(199999));
        $this->assertSame('0.00', Precio::aTexto(0));
    }

    public static function tasas(): array
    {
        return [
            ['11.5', 11500],
            ['11.5%', 11500],
            ['7', 7000],
            ['0', 0],
            ['10.5', 10500],
            ['1', 1000],
            ['abc', null],
        ];
    }

    /** @dataProvider tasas */
    public function test_convierte_las_tasas_a_milesimas(string $escrito, ?int $esperado): void
    {
        $this->assertSame($esperado, Precio::tasaAMilesimas($escrito));
    }

    public function test_muestra_las_tasas_sin_ceros_de_relleno(): void
    {
        $this->assertSame('11.5', Precio::tasaATexto(11500));
        $this->assertSame('7', Precio::tasaATexto(7000));
        $this->assertSame('0', Precio::tasaATexto(0));
        $this->assertSame('10.25', Precio::tasaATexto(10250));
    }
}
