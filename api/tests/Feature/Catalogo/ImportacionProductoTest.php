<?php

namespace Tests\Feature\Catalogo;

use App\Domain\Csv;
use App\Models\Importacion;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportacionProductoTest extends TestCase
{
    private function entrar(string $email = 'pedro@elalamo.test'): void
    {
        $this->postJson('/v1/sesion', ['email' => $email, 'password' => DatabaseSeeder::CLAVE])->assertOk();
    }

    private function archivo(string $contenido, string $nombre = 'catalogo.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $contenido);
    }

    private function subir(string $contenido, bool $actualizar = false, string $nombre = 'catalogo.csv')
    {
        return $this->post('/v1/productos/importar', [
            'archivo' => $this->archivo($contenido, $nombre),
            'actualizar_existentes' => $actualizar,
        ], ['Accept' => 'application/json']);
    }

    // --- plantilla y exportación -----------------------------------------

    public function test_la_plantilla_trae_encabezados_y_ejemplos(): void
    {
        $this->entrar();

        $r = $this->get('/v1/productos/plantilla')->assertOk();

        $contenido = $r->getContent();
        $this->assertStringStartsWith(Csv::BOM, $contenido);   // para que Excel no rompa los acentos

        $leido = Csv::leer($contenido);
        $this->assertContains('nombre_del_producto', $leido['encabezados']);
        $this->assertContains('precio_de_venta', $leido['encabezados']);
        $this->assertCount(2, $leido['filas']);
    }

    public function test_exporta_la_vista_filtrada(): void
    {
        $this->entrar();

        $r = $this->get('/v1/productos/exportar?tipo=servicio')->assertOk();
        $leido = Csv::leer($r->getContent());

        $this->assertCount(2, $leido['filas']);
        $this->assertStringContainsString('servicio', $r->headers->get('X-Nombre-Archivo'));
    }

    public function test_el_archivo_exportado_se_puede_volver_a_importar(): void
    {
        // La ida y vuelta tiene que cerrar: es lo que hace la gente para
        // corregir precios en Excel y devolverlos.
        $this->entrar();

        $exportado = $this->get('/v1/productos/exportar')->assertOk()->getContent();
        $r = $this->subir($exportado, actualizar: true)->assertOk();

        $this->assertSame(0, $r->json('errores'));
        $this->assertSame(11, $r->json('actualiza'));
        $this->assertSame(0, $r->json('nuevas'));
    }

    public function test_el_empleado_de_almacen_no_ve_el_costo_en_la_exportacion(): void
    {
        $this->entrar('carmina@elalamo.test');

        $leido = Csv::leer($this->get('/v1/productos/exportar')->assertOk()->getContent());

        $this->assertNotContains('costo', $leido['encabezados']);
        $this->assertContains('precio_de_venta', $leido['encabezados']);
    }

    // --- vista previa ------------------------------------------------------

    public function test_la_vista_previa_no_guarda_nada_todavia(): void
    {
        $this->entrar();
        $antes = Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->count();

        $csv = "Nombre del producto;Precio de venta;Existencia\nGalleta de avena;2.50;40\n";
        $r = $this->subir($csv)->assertOk();

        $this->assertSame(1, $r->json('total'));
        $this->assertSame(1, $r->json('nuevas'));
        $this->assertSame('nuevo', $r->json('filas.0.accion'));

        // Nada se creó: la vista previa solo mira (PRO-07).
        $this->assertSame($antes, Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->count());
    }

    public function test_reconoce_los_encabezados_como_los_escribe_la_gente(): void
    {
        $this->entrar();

        $csv = "PRODUCTO;Codigo;Precio;Tax;Exist\nMantecadito;MAN-01;1.75;11.5;60\n";
        $r = $this->subir($csv)->assertOk();

        $this->assertSame(0, $r->json('errores'));
        $this->assertSame('Mantecadito', $r->json('filas.0.nombre'));
    }

    public function test_avisa_fila_por_fila_que_esta_mal(): void
    {
        $this->entrar();

        $csv = implode("\n", [
            'Nombre del producto;Codigo interno;Categoria;Precio de venta;Existencia',
            ';SIN-NOMBRE;Dulces;5.00;10',                      // falta el nombre
            'Con categoria inventada;CAT-X;Inventada;5.00;10', // categoría que no existe
            'Con precio raro;PRE-X;Dulces;como diez;10',       // precio no numérico
            'Codigo repetido;PAN-001;Dulces;5.00;10',          // ya existe y no se pidió actualizar
            'Bien formado;NUE-01;Dulces;5.00;10',
        ])."\n";

        $r = $this->subir($csv)->assertOk();

        $this->assertSame(5, $r->json('total'));
        $this->assertSame(4, $r->json('errores'));
        $this->assertSame(1, $r->json('nuevas'));

        $filas = collect($r->json('filas'));
        $this->assertStringContainsString('Falta el nombre', $filas[0]['errores'][0]);
        $this->assertStringContainsString('no existe', $filas[1]['errores'][0]);
        $this->assertStringContainsString('no es un número', $filas[2]['errores'][0]);
        $this->assertStringContainsString('actualizar los que ya existen', $filas[3]['errores'][0]);
        $this->assertSame([], $filas[4]['errores']);
    }

    public function test_detecta_codigos_repetidos_dentro_del_mismo_archivo(): void
    {
        $this->entrar();

        $csv = "Nombre del producto;Codigo interno;Precio de venta\nUno;REP-01;1.00\nOtro;rep-01;2.00\n";
        $r = $this->subir($csv)->assertOk();

        $this->assertSame(1, $r->json('errores'));
        $this->assertStringContainsString('repetido en la fila 2', $r->json('filas.1.errores.0'));
    }

    public function test_un_servicio_con_existencia_es_un_error(): void
    {
        $this->entrar();

        $csv = "Nombre del producto;Precio de venta;Existencia;Es servicio (si/no)\nDecoracion;100;15;si\n";
        $r = $this->subir($csv)->assertOk();

        $this->assertSame(1, $r->json('errores'));
        $this->assertStringContainsString('no lleva existencia', $r->json('filas.0.errores.0'));
    }

    public function test_el_informe_de_errores_se_puede_descargar(): void
    {
        $this->entrar();

        $csv = "Nombre del producto;Precio de venta\n;5.00\nBien;2.00\n";
        $id = $this->subir($csv)->assertOk()->json('id');

        $informe = $this->get("/v1/importaciones/{$id}/errores")->assertOk()->getContent();
        $leido = Csv::leer($informe);

        $this->assertCount(1, $leido['filas']);
        $this->assertStringContainsString('Falta el nombre', $leido['filas'][0]['que_hay_que_arreglar']);
    }

    // --- confirmación ------------------------------------------------------

    public function test_al_confirmar_crea_los_productos_y_su_apertura(): void
    {
        $this->entrar();

        $csv = "Nombre del producto;Codigo interno;Categoria;Precio de venta;Costo;Existencia;Existencia minima\n"
            ."Galleta de avena;GAL-01;Dulces;2.50;1.00;40;10\n";

        $id = $this->subir($csv)->assertOk()->json('id');

        $r = $this->postJson("/v1/importaciones/{$id}/confirmar")->assertOk();
        $this->assertSame(1, $r->json('nuevos'));

        $creado = $this->getJson('/v1/productos?buscar=Galleta de avena')->assertOk()->json('datos.0');
        $this->assertSame('2.50', $creado['precio']);
        $this->assertSame('1.00', $creado['costo']);
        $this->assertEquals(40.0, $creado['existencia']);
        $this->assertSame('Dulces', $creado['categoria']['nombre']);

        // La existencia entró como movimiento, no como campo tecleado (INV-01).
        $producto = Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'GAL-01')->firstOrFail();
        $movimiento = MovimientoInventario::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('producto_id', $producto->id)->firstOrFail();

        $this->assertSame('apertura', $movimiento->tipo);
        $this->assertSame('importación', $movimiento->motivo);
    }

    public function test_actualiza_por_codigo_cuando_se_pide(): void
    {
        $this->entrar();

        $csv = "Nombre del producto;Codigo interno;Precio de venta\nPan de Leche relleno de chocolate;PAN-001;12.00\n";
        $r = $this->subir($csv, actualizar: true)->assertOk();

        $this->assertSame(1, $r->json('actualiza'));
        $this->assertSame('actualiza', $r->json('filas.0.accion'));

        $this->postJson('/v1/importaciones/'.$r->json('id').'/confirmar')->assertOk()->assertJsonPath('actualizados', 1);

        $pan = $this->getJson('/v1/productos?buscar=PAN-001')->json('datos.0');
        $this->assertSame('12.00', $pan['precio']);
        // Actualizar el precio no toca la existencia: para eso está el ajuste.
        $this->assertEquals(115.0, $pan['existencia']);
    }

    public function test_o_entra_todo_o_no_entra_nada(): void
    {
        // PRO-08: una importación es atómica.
        $this->entrar();
        $antes = Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->count();

        $csv = "Nombre del producto;Codigo interno;Precio de venta\nPrimero;ATO-01;1.00\nSegundo;ATO-02;2.00\n";
        $id = $this->subir($csv)->assertOk()->json('id');

        // Alguien borra la categoría… no: se simula un fallo dejando la
        // importación cerrada entre medio.
        Importacion::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('ulid', $id)->update(['estado' => 'descartada']);

        $this->postJson("/v1/importaciones/{$id}/confirmar")->assertStatus(409);

        $this->assertSame($antes, Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->count());
    }

    public function test_no_se_puede_confirmar_dos_veces(): void
    {
        $this->entrar();

        $csv = "Nombre del producto;Codigo interno;Precio de venta\nUnico;UNI-01;1.00\n";
        $id = $this->subir($csv)->assertOk()->json('id');

        $this->postJson("/v1/importaciones/{$id}/confirmar")->assertOk();
        $this->postJson("/v1/importaciones/{$id}/confirmar")->assertStatus(409);

        $this->assertSame(1, Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'UNI-01')->count());
    }

    public function test_un_archivo_todo_malo_no_se_puede_confirmar(): void
    {
        $this->entrar();

        $csv = "Nombre del producto;Precio de venta\n;1.00\n;2.00\n";
        $id = $this->subir($csv)->assertOk()->json('id');

        $this->postJson("/v1/importaciones/{$id}/confirmar")
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'nada_que_importar');
    }

    // --- lo que debe fallar (CAL-03) ---------------------------------------

    public function test_rechaza_un_archivo_vacio(): void
    {
        $this->entrar();

        $this->subir("   \n")->assertStatus(422)->assertJsonPath('codigo', 'archivo_vacio');
    }

    public function test_rechaza_un_archivo_sin_filas(): void
    {
        $this->entrar();

        $this->subir("Nombre del producto;Precio de venta\n")->assertStatus(422)->assertJsonPath('codigo', 'sin_filas');
    }

    public function test_el_contador_no_puede_importar(): void
    {
        $this->entrar('sonia@elalamo.test');

        // Ve el catálogo y puede exportar, pero no importar.
        $this->get('/v1/productos/exportar')->assertOk();
        $this->subir("Nombre del producto;Precio de venta\nAlgo;1.00\n")->assertStatus(403);
    }

    public function test_no_se_alcanza_una_importacion_de_otra_empresa(): void
    {
        $this->entrar();
        $id = $this->subir("Nombre del producto;Precio de venta\nAlgo;1.00\n")->assertOk()->json('id');

        // Otra empresa, otro usuario: para él esa importación no existe (ARQ-21).
        $this->postJson('/v1/sesion', ['email' => 'carlos@innovacion.test', 'password' => DatabaseSeeder::CLAVE])->assertOk();

        $this->get("/v1/importaciones/{$id}/errores")->assertStatus(404);
        $this->postJson("/v1/importaciones/{$id}/confirmar")->assertStatus(404);
    }

    public function test_una_empresa_suspendida_exporta_pero_no_importa(): void
    {
        $this->entrar('carlos@innovacion.test');

        $this->get('/v1/productos/exportar')->assertOk();
        $this->subir("Nombre del producto;Precio de venta\nAlgo;1.00\n")->assertStatus(423);
    }
}
