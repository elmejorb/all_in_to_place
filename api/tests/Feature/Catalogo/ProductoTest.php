<?php

namespace Tests\Feature\Catalogo;

use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Soporte\ContextoRls;
use App\Soporte\Inventario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductoTest extends TestCase
{
    // Nota: los números se comparan con assertEquals, no con assertSame. JSON
    // tiene un solo tipo numérico y 115.0 viaja como 115.

    private function entrar(string $email = 'pedro@elalamo.test'): void
    {
        $this->postJson('/v1/sesion', ['email' => $email, 'password' => DatabaseSeeder::CLAVE])->assertOk();
    }

    private function productoDe(string $empresa, string $sku): Producto
    {
        $empresaId = Empresa::on('pgsql_migrator')->where('nombre_comercial', $empresa)->value('id');

        return Producto::on('pgsql_migrator')
            ->withoutGlobalScope('empresa')
            ->where('empresa_id', $empresaId)
            ->where('sku', $sku)
            ->firstOrFail();
    }

    // --- listado y filtros ----------------------------------------------

    public function test_lista_los_productos_activos_con_su_precio_y_margen(): void
    {
        $this->entrar();

        $r = $this->getJson('/v1/productos?buscar=Pan de Leche')->assertOk();

        $pan = $r->json('datos.0');
        $this->assertSame('Pan de Leche relleno de chocolate', $pan['nombre']);
        $this->assertSame('9.50', $pan['precio']);
        $this->assertSame('5.99', $pan['costo']);
        $this->assertSame(36.95, $pan['margen']);          // (950 - 599) / 950
        $this->assertSame('11.5', $pan['impuesto']);
        $this->assertEquals(115.0, $pan['existencia']);
        $this->assertSame('normal', $pan['estado_existencia']);
        $this->assertSame('Pastelería', $pan['categoria']['nombre']);
        $this->assertSame('Harinas del Caribe', $pan['suplidor']['nombre']);
    }

    public function test_busca_por_nombre_sku_y_codigo_de_barras(): void
    {
        $this->entrar();

        $this->getJson('/v1/productos?buscar=mallorcas')->assertOk()->assertJsonCount(1, 'datos');
        $this->getJson('/v1/productos?buscar=TOR-001')->assertOk()->assertJsonCount(1, 'datos');
        // El lector de códigos escribe el número exacto (PRO-05).
        $this->getJson('/v1/productos?buscar=7451000010015')->assertOk()->assertJsonCount(1, 'datos');
    }

    public function test_filtra_por_estado_de_existencia(): void
    {
        $this->entrar();

        // Café colado está en cero.
        $agotados = $this->getJson('/v1/productos?existencia=agotado')->assertOk()->json('datos');
        $this->assertSame(['Café colado 12 oz'], array_column($agotados, 'nombre'));

        // Jugo tiene 8 con mínimo 12.
        $bajos = $this->getJson('/v1/productos?existencia=bajo')->assertOk()->json('datos');
        $this->assertSame(['Jugo natural de china'], array_column($bajos, 'nombre'));
    }

    public function test_filtra_por_categoria_suplidor_y_tipo(): void
    {
        $this->entrar();

        $categoria = $this->getJson('/v1/categorias?buscar=Bebidas')->json('datos.0.id');
        $this->getJson('/v1/productos?categoria='.$categoria)->assertOk()->assertJsonCount(2, 'datos');

        $suplidor = $this->getJson('/v1/suplidores?buscar=Harinas')->json('datos.0.id');
        $this->getJson('/v1/productos?suplidor='.$suplidor)->assertOk()->assertJsonCount(4, 'datos');

        $servicios = $this->getJson('/v1/productos?tipo=servicio')->assertOk()->json('datos');
        $this->assertCount(2, $servicios);
        // Un servicio no tiene existencia que mostrar (PRO-03).
        $this->assertNull($servicios[0]['existencia']);
        $this->assertNull($servicios[0]['estado_existencia']);
    }

    public function test_el_inactivo_no_sale_salvo_que_se_pida(): void
    {
        $this->entrar();

        $this->getJson('/v1/productos?buscar=descontinuado')->assertOk()->assertJsonCount(0, 'datos');
        $this->getJson('/v1/productos?buscar=descontinuado&estado=inactivos')->assertOk()->assertJsonCount(1, 'datos');
    }

    public function test_el_contador_ve_costos_y_el_cajero_no(): void
    {
        // La matriz del documento 04: ver costos no es para todos los roles.
        $this->entrar('sonia@elalamo.test');
        $conCostos = $this->getJson('/v1/productos?buscar=Torta')->assertOk()->json('datos.0');
        $this->assertArrayHasKey('costo', $conCostos);
        $this->assertArrayHasKey('margen', $conCostos);

        $this->entrar('carmina@elalamo.test');   // empleado de almacén
        $sinCostos = $this->getJson('/v1/productos?buscar=Torta')->assertOk()->json('datos.0');
        $this->assertArrayNotHasKey('costo', $sinCostos);
        $this->assertArrayNotHasKey('margen', $sinCostos);
        $this->assertArrayHasKey('precio', $sinCostos);
    }

    // --- alta y edición --------------------------------------------------

    public function test_crea_un_producto_con_existencia_de_apertura(): void
    {
        $this->entrar();

        $categoria = $this->getJson('/v1/categorias?buscar=Dulces')->json('datos.0.id');

        $r = $this->postJson('/v1/productos', [
            'nombre' => 'Flan de queso',
            'sku' => 'FLA-001',
            'categoria' => $categoria,
            'unidad' => 'unidad',
            'costo' => '2.25',
            'precio' => '5.00',
            'impuesto' => '11.5',
            'existencia_inicial' => 30,
            'existencia_minima' => 6,
        ])->assertCreated();

        $this->assertSame('5.00', $r->json('precio'));
        $this->assertEquals(55.0, $r->json('margen'));
        $this->assertEquals(30.0, $r->json('existencia'));

        // La existencia entró como movimiento, no como campo tecleado (INV-01).
        $producto = $this->productoDe('Panadería El Álamo', 'FLA-001');
        $movimiento = MovimientoInventario::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('producto_id', $producto->id)->firstOrFail();

        $this->assertSame('apertura', $movimiento->tipo);
        $this->assertSame('30.000', $movimiento->cantidad);
    }

    public function test_un_servicio_no_pide_ni_acepta_existencia(): void
    {
        $this->entrar();

        $r = $this->postJson('/v1/productos', [
            'nombre' => 'Alquiler de salón',
            'es_servicio' => true,
            'precio' => '300.00',
            'existencia_inicial' => 50,     // se descarta (PRO-03)
            'existencia_minima' => 10,
        ])->assertCreated();

        $this->assertNull($r->json('existencia'));
        $this->assertTrue($r->json('es_servicio'));

        $producto = Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('nombre', 'Alquiler de salón')->firstOrFail();

        $this->assertSame(0, MovimientoInventario::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('producto_id', $producto->id)->count());
    }

    public function test_avisa_cuando_el_precio_queda_bajo_el_costo(): void
    {
        $this->entrar();

        $r = $this->postJson('/v1/productos', [
            'nombre' => 'Vendido con pérdida',
            'costo' => '10.00',
            'precio' => '8.00',
        ])->assertCreated();

        $this->assertTrue($r->json('vende_bajo_costo'));
        $this->assertEquals(-25.0, $r->json('margen'));
    }

    public function test_edita_precio_y_recalcula_el_margen(): void
    {
        $this->entrar();
        $producto = $this->productoDe('Panadería El Álamo', 'TOR-001');

        $this->putJson('/v1/productos/'.$producto->ulid, [
            'nombre' => 'Torta chocolate media libra',
            'costo' => '60.00',
            'precio' => '120.00',
            'impuesto' => '11.5',
            'existencia_minima' => 5,
        ])->assertOk();

        $this->assertEquals(50.0, $this->getJson('/v1/productos?buscar=Torta')->json('datos.0.margen'));
    }

    // --- inventario ------------------------------------------------------

    public function test_ajustar_deja_rastro_y_cambia_la_existencia(): void
    {
        $this->entrar();
        $producto = $this->productoDe('Panadería El Álamo', 'PAN-002');   // tiene 25

        $r = $this->postJson('/v1/productos/'.$producto->ulid.'/ajustar', [
            'contado' => 22,
            'motivo' => 'conteo',
            'comentario' => 'Conteo del lunes',
        ])->assertOk();

        $this->assertEquals(-3.0, $r->json('diferencia'));
        $this->assertEquals(22.0, $r->json('producto.existencia'));

        $movimiento = MovimientoInventario::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('producto_id', $producto->id)->orderByDesc('id')->firstOrFail();

        $this->assertSame('ajuste', $movimiento->tipo);
        $this->assertSame('conteo', $movimiento->motivo);
        $this->assertSame('Conteo del lunes', $movimiento->comentario);
        $this->assertNotNull($movimiento->usuario_id);
    }

    public function test_el_ajuste_exige_motivo(): void
    {
        $this->entrar();
        $producto = $this->productoDe('Panadería El Álamo', 'PAN-002');

        $this->postJson('/v1/productos/'.$producto->ulid.'/ajustar', ['contado' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->postJson('/v1/productos/'.$producto->ulid.'/ajustar', ['contado' => 10, 'motivo' => 'porque sí'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');
    }

    public function test_el_kardex_muestra_el_saldo_corrido(): void
    {
        $this->entrar();
        $producto = $this->productoDe('Panadería El Álamo', 'PAN-003');   // 33 de apertura

        $this->postJson('/v1/productos/'.$producto->ulid.'/ajustar', ['contado' => 30, 'motivo' => 'merma'])->assertOk();

        $r = $this->getJson('/v1/productos/'.$producto->ulid.'/movimientos')->assertOk();

        $this->assertCount(2, $r->json('datos'));
        $this->assertSame('ajuste', $r->json('datos.0.tipo'));
        $this->assertEquals(-3.0, $r->json('datos.0.cantidad'));
        $this->assertEquals(30.0, $r->json('datos.0.existencia_resultante'));
        $this->assertSame('apertura', $r->json('datos.1.tipo'));
        $this->assertEquals(33.0, $r->json('datos.1.existencia_resultante'));
    }

    public function test_la_existencia_siempre_cuadra_con_sus_movimientos(): void
    {
        // INV-02: la columna es caché, la verdad son los movimientos.
        $this->entrar();
        $producto = $this->productoDe('Panadería El Álamo', 'DON-001');

        $this->postJson('/v1/productos/'.$producto->ulid.'/ajustar', ['contado' => 55, 'motivo' => 'conteo'])->assertOk();

        ContextoRls::fijar(ContextoRls::EMPRESA, $producto->empresa_id);
        $recalculada = Inventario::recalcular(Producto::query()->findOrFail($producto->id));

        $this->assertEquals(55.0, $recalculada);
    }

    public function test_un_servicio_no_se_puede_ajustar(): void
    {
        $this->entrar();
        $servicio = $this->productoDe('Panadería El Álamo', 'ENC-001');

        $this->postJson('/v1/productos/'.$servicio->ulid.'/ajustar', ['contado' => 5, 'motivo' => 'conteo'])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'sin_inventario');
    }

    public function test_los_movimientos_no_se_pueden_editar_ni_borrar(): void
    {
        // El permiso se lo quitó la base a la aplicación, no solo el código.
        $this->entrar();
        ContextoRls::fijar(ContextoRls::EMPRESA, Empresa::on('pgsql_migrator')->where('nombre_comercial', 'Panadería El Álamo')->value('id'));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::connection('pgsql')->update('update movimiento_inventario set cantidad = 9999');
    }

    // --- lo que debe fallar (CAL-03) -------------------------------------

    public function test_no_repite_el_sku_ni_el_codigo_de_barras(): void
    {
        $this->entrar();

        $this->postJson('/v1/productos', ['nombre' => 'Otro', 'sku' => 'PAN-001'])
            ->assertStatus(422)->assertJsonValidationErrors('sku');

        // Ni cambiando mayúsculas.
        $this->postJson('/v1/productos', ['nombre' => 'Otro', 'sku' => 'pan-001'])
            ->assertStatus(422)->assertJsonValidationErrors('sku');

        $this->postJson('/v1/productos', ['nombre' => 'Otro', 'codigo_barras' => '7451000010015'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo_barras');
    }

    public function test_rechaza_importes_que_no_son_numeros(): void
    {
        $this->entrar();

        $this->postJson('/v1/productos', ['nombre' => 'Con precio raro', 'precio' => 'como diez pesos'])
            ->assertStatus(422)->assertJsonValidationErrors('precio');
    }

    public function test_no_acepta_una_categoria_de_otra_empresa(): void
    {
        $ajenaId = \App\Models\Categoria::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('nombre', 'Papelería')->value('ulid');

        $this->entrar();

        $this->postJson('/v1/productos', ['nombre' => 'Intento', 'categoria' => $ajenaId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('categoria');
    }

    public function test_no_alcanza_un_producto_de_otra_empresa(): void
    {
        $ajeno = $this->productoDe('Innovación Digital', 'PAP-001');

        $this->entrar();

        $this->getJson('/v1/productos?buscar=Resma')->assertOk()->assertJsonCount(0, 'datos');
        $this->putJson('/v1/productos/'.$ajeno->ulid, ['nombre' => 'Secuestrado'])->assertStatus(404);
        $this->postJson('/v1/productos/'.$ajeno->ulid.'/ajustar', ['contado' => 0, 'motivo' => 'conteo'])->assertStatus(404);
        $this->getJson('/v1/productos/'.$ajeno->ulid.'/movimientos')->assertStatus(404);

        $this->assertSame('Resma de papel carta', $ajeno->fresh()->nombre);
    }

    public function test_el_empleado_de_almacen_no_crea_productos(): void
    {
        $this->entrar('carmina@elalamo.test');

        $this->getJson('/v1/productos')->assertOk();
        $this->postJson('/v1/productos', ['nombre' => 'No debería'])->assertStatus(403);
    }
}
