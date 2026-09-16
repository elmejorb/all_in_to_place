<?php

namespace Tests\Feature\Facturacion;

use App\Models\Cliente;
use App\Models\Documento;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\SerieDocumento;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

class FacturaTest extends TestCase
{
    /** La numeración se arrastra de una prueba a otra: cada una parte de cero. */
    protected bool $sembrarPorPrueba = true;

    private function entrar(string $email = 'pedro@elalamo.test'): void
    {
        $this->postJson('/v1/sesion', ['email' => $email, 'password' => DatabaseSeeder::CLAVE])->assertOk();
    }

    private function ulidProducto(string $sku): string
    {
        return Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', $sku)->value('ulid');
    }

    private function ulidCliente(string $nombre): string
    {
        return Cliente::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('nombre', $nombre)->value('ulid');
    }

    /** Una venta simple: dos panes de leche a 9.50 con 11.5%. */
    private function ventaSimple(array $extra = []): array
    {
        return array_merge([
            'renglones' => [
                ['producto' => $this->ulidProducto('PAN-001'), 'cantidad' => 2],
            ],
        ], $extra);
    }

    // --- cálculo previo -----------------------------------------------------

    public function test_calcula_sin_guardar_nada(): void
    {
        $this->entrar();
        $antes = Documento::on('pgsql_migrator')->withoutGlobalScope('empresa')->count();

        $r = $this->postJson('/v1/facturas/calcular', $this->ventaSimple())->assertOk();

        $this->assertSame('19.00', $r->json('subtotal'));
        $this->assertSame('2.19', $r->json('impuesto'));
        $this->assertSame('21.19', $r->json('total'));

        $this->assertSame($antes, Documento::on('pgsql_migrator')->withoutGlobalScope('empresa')->count());
    }

    public function test_desglosa_el_impuesto_como_lo_configura_la_empresa(): void
    {
        $this->entrar();

        $r = $this->postJson('/v1/facturas/calcular', [
            'renglones' => [['producto' => $this->ulidProducto('TOR-001'), 'cantidad' => 1]],
        ])->assertOk();

        // 95.00 al 11.5% = 10.93, repartido 10.5 estatal y 1 municipal.
        $this->assertSame('10.93', $r->json('impuesto'));
        $this->assertSame('Estatal', $r->json('desglose.0.nombre'));
        $this->assertSame('9.98', $r->json('desglose.0.monto'));
        $this->assertSame('Municipal', $r->json('desglose.1.nombre'));
        $this->assertSame('0.95', $r->json('desglose.1.monto'));
    }

    public function test_un_cliente_exento_no_paga_impuesto(): void
    {
        // FAC-06: el documento tiene que decir por qué.
        $this->entrar();

        $r = $this->postJson('/v1/facturas/calcular', $this->ventaSimple([
            'cliente' => $this->ulidCliente('Colegio San Antonio'),
        ]))->assertOk();

        $this->assertSame('0.00', $r->json('impuesto'));
        $this->assertSame('19.00', $r->json('total'));
        $this->assertSame([], $r->json('desglose'));
    }

    // --- emisión ------------------------------------------------------------

    public function test_emite_con_folio_y_descuenta_el_inventario(): void
    {
        $this->entrar();
        $antes = (float) Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'PAN-001')->value('existencia');

        $r = $this->postJson('/v1/facturas', $this->ventaSimple([
            'cliente' => $this->ulidCliente('Cafetería La Esquina'),
            'pagos' => [['metodo' => 'efectivo', 'monto' => '21.19', 'recibido' => '25.00']],
        ]))->assertCreated();

        $this->assertSame('F-00001', $r->json('folio'));
        $this->assertSame('pagada', $r->json('estado'));
        $this->assertSame('21.19', $r->json('total'));
        $this->assertSame('0.00', $r->json('saldo'));
        $this->assertSame('3.81', $r->json('pagos.0.cambio'));       // 25.00 - 21.19

        // FAC-08: el inventario baja en la misma transacción.
        $despues = (float) Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'PAN-001')->value('existencia');
        $this->assertSame($antes - 2, $despues);

        $movimiento = MovimientoInventario::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('referencia_tipo', 'documento')->orderByDesc('id')->firstOrFail();
        $this->assertSame('venta', $movimiento->tipo);
        $this->assertSame('-2.000', $movimiento->cantidad);
    }

    public function test_copia_el_nombre_del_cliente_y_la_descripcion(): void
    {
        // RNF-12: la factura de ayer tiene que seguir diciendo lo que decía.
        $this->entrar();

        $id = $this->postJson('/v1/facturas', $this->ventaSimple([
            'cliente' => $this->ulidCliente('Cafetería La Esquina'),
        ]))->assertCreated()->json('id');

        Cliente::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('nombre', 'Cafetería La Esquina')->update(['nombre' => 'Otro nombre ahora']);
        Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('sku', 'PAN-001')->update(['nombre' => 'Otro producto ahora']);

        $r = $this->getJson("/v1/facturas/{$id}")->assertOk();

        $this->assertSame('Cafetería La Esquina', $r->json('cliente'));
        $this->assertSame('Pan de Leche relleno de chocolate', $r->json('renglones.0.descripcion'));
    }

    public function test_la_numeracion_no_deja_huecos_ni_repite(): void
    {
        // ARQ-08. Se emiten varias seguidas y los números tienen que ser
        // consecutivos, sin saltos.
        $this->entrar();

        $folios = [];
        for ($i = 0; $i < 5; $i++) {
            $folios[] = $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated()->json('folio');
        }

        $this->assertSame(['F-00001', 'F-00002', 'F-00003', 'F-00004', 'F-00005'], $folios);
        $this->assertSame(5, count(array_unique($folios)));

        $serie = SerieDocumento::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('tipo', 'factura')->firstOrFail();
        $this->assertSame(6, $serie->proximo_numero);
    }

    public function test_cada_empresa_tiene_su_propia_numeracion(): void
    {
        $this->entrar();
        $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated()->assertJsonPath('folio', 'F-00001');

        // Otra empresa arranca en uno otra vez.
        $this->postJson('/v1/sesion', ['email' => 'luis@elalamo.test', 'password' => DatabaseSeeder::CLAVE])->assertOk();
        $santaMonica = Empresa::on('pgsql_migrator')->where('nombre_comercial', 'El Álamo Santa Mónica')->firstOrFail();
        $this->postJson('/v1/sesion/empresa', ['empresa' => $santaMonica->ulid])->assertOk();

        $this->postJson('/v1/facturas', [
            'renglones' => [['producto' => $this->ulidProducto('PA-001'), 'cantidad' => 1]],
        ])->assertCreated()->assertJsonPath('folio', 'F-00001');
    }

    public function test_avisa_cuando_no_hay_existencia_y_deja_decidir(): void
    {
        // FAC-08: la caja decide si vende igual o no.
        $this->entrar();

        $venta = ['renglones' => [['producto' => $this->ulidProducto('CAF-012'), 'cantidad' => 3]]];   // está en cero

        $r = $this->postJson('/v1/facturas', $venta)->assertStatus(409);
        $this->assertSame('sin_existencia', $r->json('codigo'));
        $this->assertSame('Café colado 12 oz', $r->json('faltantes.0.producto'));
        $this->assertEquals(0, $r->json('faltantes.0.disponible'));

        // Y si se autoriza, se vende y la existencia queda en negativo.
        $this->postJson('/v1/facturas', $venta + ['permitir_sin_existencia' => true])->assertCreated();
        $this->assertEquals(-3, (float) Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'CAF-012')->value('existencia'));
    }

    public function test_un_servicio_no_toca_el_inventario(): void
    {
        $this->entrar();

        $this->postJson('/v1/facturas', [
            'renglones' => [['producto' => $this->ulidProducto('ENC-001'), 'cantidad' => 1]],
        ])->assertCreated();

        $servicio = Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'ENC-001')->firstOrFail();
        $this->assertSame(0, MovimientoInventario::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('producto_id', $servicio->id)->count());
    }

    public function test_renglon_de_texto_libre_sin_producto(): void
    {
        $this->entrar();

        $r = $this->postJson('/v1/facturas', [
            'renglones' => [['descripcion' => 'Entrega a domicilio', 'cantidad' => 1, 'precio' => '5.00']],
        ])->assertCreated();

        $this->assertSame('Entrega a domicilio', $r->json('renglones.0.descripcion'));
        $this->assertSame('5.00', $r->json('total'));
    }

    // --- cobro --------------------------------------------------------------

    public function test_pagos_mixtos_y_estado_que_se_calcula_solo(): void
    {
        // FAC-02 y FAC-07.
        $this->entrar();

        $factura = $this->postJson('/v1/facturas', $this->ventaSimple([
            'pagos' => [['metodo' => 'efectivo', 'monto' => '10.00']],
        ]))->assertCreated();

        $this->assertSame('pagada_parcial', $factura->json('estado'));
        $this->assertSame('11.19', $factura->json('saldo'));

        $id = $factura->json('id');
        $r = $this->postJson("/v1/facturas/{$id}/cobrar", ['metodo' => 'ath_movil', 'monto' => '11.19', 'referencia' => 'ATH-993'])
            ->assertOk();

        $this->assertSame('pagada', $r->json('estado'));
        $this->assertSame('0.00', $r->json('saldo'));
        $this->assertCount(2, $r->json('pagos'));
    }

    public function test_no_se_puede_cobrar_mas_que_el_saldo(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated()->json('id');

        $this->postJson("/v1/facturas/{$id}/cobrar", ['metodo' => 'efectivo', 'monto' => '999.00'])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'no_se_puede_cobrar');
    }

    // --- anulación ----------------------------------------------------------

    public function test_anular_devuelve_la_mercancia_y_conserva_el_numero(): void
    {
        // FAC-09: la factura no se borra.
        $this->entrar();
        $antes = (float) Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'PAN-001')->value('existencia');

        $factura = $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated();
        $id = $factura->json('id');

        $r = $this->postJson("/v1/facturas/{$id}/anular", ['motivo' => 'El cliente se arrepintió'])->assertOk();

        $this->assertSame('anulada', $r->json('estado'));
        $this->assertSame('El cliente se arrepintió', $r->json('motivo_anulacion'));
        $this->assertSame('F-00001', $r->json('folio'));

        // La mercancía volvió.
        $this->assertSame($antes, (float) Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'PAN-001')->value('existencia'));

        // Y el número queda ocupado: la siguiente es la dos.
        $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated()->assertJsonPath('folio', 'F-00002');
    }

    public function test_anular_exige_motivo(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated()->json('id');

        $this->postJson("/v1/facturas/{$id}/anular", [])->assertStatus(422)->assertJsonValidationErrors('motivo');
    }

    public function test_una_factura_anulada_no_se_cobra_ni_se_vuelve_a_anular(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated()->json('id');
        $this->postJson("/v1/facturas/{$id}/anular", ['motivo' => 'Error de captura'])->assertOk();

        $this->postJson("/v1/facturas/{$id}/cobrar", ['metodo' => 'efectivo', 'monto' => '1.00'])->assertStatus(422);
        $this->postJson("/v1/facturas/{$id}/anular", ['motivo' => 'Otra vez'])->assertStatus(422);
    }

    public function test_una_factura_emitida_no_se_puede_borrar(): void
    {
        // El permiso se lo quitó la base a la aplicación, no solo el código.
        $this->entrar();
        $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated();

        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::connection('pgsql')->delete('delete from documento');
    }

    // --- listado ------------------------------------------------------------

    public function test_el_listado_resume_lo_filtrado(): void
    {
        $this->entrar();

        $this->postJson('/v1/facturas', $this->ventaSimple(['pagos' => [['metodo' => 'efectivo', 'monto' => '21.19']]]))->assertCreated();
        $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated();

        $r = $this->getJson('/v1/facturas')->assertOk();

        $this->assertSame(2, $r->json('resumen.cantidad'));
        $this->assertSame('42.38', $r->json('resumen.total'));
        $this->assertSame('21.19', $r->json('resumen.por_cobrar'));

        $pendientes = $this->getJson('/v1/facturas?estado=pendientes')->assertOk();
        $this->assertCount(1, $pendientes->json('datos'));
    }

    // --- lo que debe fallar (CAL-03) ---------------------------------------

    public function test_una_factura_sin_renglones_no_se_emite(): void
    {
        $this->entrar();

        $this->postJson('/v1/facturas', ['renglones' => []])->assertStatus(422)->assertJsonValidationErrors('renglones');
    }

    public function test_no_acepta_cantidades_cero_o_negativas(): void
    {
        $this->entrar();

        $this->postJson('/v1/facturas', ['renglones' => [['producto' => $this->ulidProducto('PAN-001'), 'cantidad' => 0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('renglones.0.cantidad');
    }

    public function test_no_alcanza_una_factura_de_otra_empresa(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated()->json('id');

        $this->postJson('/v1/sesion', ['email' => 'carlos@innovacion.test', 'password' => DatabaseSeeder::CLAVE])->assertOk();

        $this->getJson("/v1/facturas/{$id}")->assertStatus(404);
        $this->postJson("/v1/facturas/{$id}/anular", ['motivo' => 'Ajena'])->assertStatus(404);
    }

    public function test_el_de_mostrador_factura_pero_no_anula(): void
    {
        // La matriz del documento 04: el cajero vende, pero no deshace.
        $this->entrar('emily@elalamo.test');

        $id = $this->postJson('/v1/facturas', $this->ventaSimple())->assertCreated()->json('id');
        $this->postJson("/v1/facturas/{$id}/anular", ['motivo' => 'No deberia'])->assertStatus(403);
    }

    public function test_el_de_almacen_no_factura(): void
    {
        $this->entrar('carmina@elalamo.test');

        $this->getJson('/v1/facturas')->assertStatus(403);
        $this->postJson('/v1/facturas', $this->ventaSimple())->assertStatus(403);
    }

    public function test_una_empresa_suspendida_consulta_pero_no_factura(): void
    {
        $this->entrar('carlos@innovacion.test');

        $this->getJson('/v1/facturas')->assertOk();
        $this->postJson('/v1/facturas', [
            'renglones' => [['descripcion' => 'Algo', 'cantidad' => 1, 'precio' => '1.00']],
        ])->assertStatus(423);
    }
}
