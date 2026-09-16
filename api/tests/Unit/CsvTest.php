<?php

namespace Tests\Unit;

use App\Domain\Csv;
use PHPUnit\Framework\TestCase;

/**
 * Casos escritos antes de la implementación (CAL-11), con los archivos reales
 * que salen de un Excel en español.
 */
class CsvTest extends TestCase
{
    public function test_lee_un_csv_separado_por_comas(): void
    {
        $leido = Csv::leer("nombre,precio\nPan de leche,9.50\nCafe,2.50\n");

        $this->assertSame(['nombre', 'precio'], $leido['encabezados']);
        $this->assertCount(2, $leido['filas']);
        $this->assertSame('Pan de leche', $leido['filas'][0]['nombre']);
        $this->assertSame('9.50', $leido['filas'][0]['precio']);
    }

    public function test_lee_el_punto_y_coma_que_usa_excel_en_espanol(): void
    {
        $leido = Csv::leer("nombre;precio;costo\nPan;9,50;5,99\n");

        $this->assertSame(['nombre', 'precio', 'costo'], $leido['encabezados']);
        $this->assertSame('9,50', $leido['filas'][0]['precio']);
    }

    public function test_se_come_el_bom_sin_romper_el_primer_encabezado(): void
    {
        $leido = Csv::leer(Csv::BOM."nombre;precio\nPan;1.00\n");

        // Sin esto el primer encabezado llegaría como "\u{feff}nombre" y nunca coincidiría.
        $this->assertSame('nombre', $leido['encabezados'][0]);
        $this->assertSame('Pan', $leido['filas'][0]['nombre']);
    }

    public function test_convierte_lo_que_viene_en_windows_1252(): void
    {
        $contenido = mb_convert_encoding("nombre\nPanadería El Álamo\n", 'Windows-1252', 'UTF-8');
        $leido = Csv::leer($contenido);

        $this->assertSame('Panadería El Álamo', $leido['filas'][0]['nombre']);
    }

    public function test_normaliza_los_encabezados(): void
    {
        $leido = Csv::leer("Nombre del Producto;Precio de Venta;CÓDIGO DE BARRAS\nPan;1.00;123\n");

        $this->assertSame(['nombre_del_producto', 'precio_de_venta', 'codigo_de_barras'], $leido['encabezados']);
    }

    public function test_salta_las_lineas_vacias(): void
    {
        $leido = Csv::leer("nombre\nPan\n\n\nCafe\n");

        $this->assertCount(2, $leido['filas']);
    }

    public function test_respeta_las_comillas_y_las_comas_dentro_del_texto(): void
    {
        $leido = Csv::leer("nombre,descripcion\n\"Pan, grande\",\"Con \"\"relleno\"\"\"\n");

        $this->assertSame('Pan, grande', $leido['filas'][0]['nombre']);
        $this->assertSame('Con "relleno"', $leido['filas'][0]['descripcion']);
    }

    public function test_escribe_con_bom_para_que_excel_no_rompa_los_acentos(): void
    {
        $csv = Csv::escribir(['nombre', 'precio'], [['Panadería', '9.50']]);

        $this->assertStringStartsWith(Csv::BOM, $csv);
        $this->assertStringContainsString('Panadería', $csv);
        $this->assertStringContainsString('nombre;precio', $csv);
    }

    public function test_lo_que_escribe_lo_vuelve_a_leer_igual(): void
    {
        $csv = Csv::escribir(['nombre', 'notas'], [['Pan, grande', 'Con "relleno"']]);
        $leido = Csv::leer($csv);

        $this->assertSame('Pan, grande', $leido['filas'][0]['nombre']);
        $this->assertSame('Con "relleno"', $leido['filas'][0]['notas']);
    }
}
