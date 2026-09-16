<?php

namespace Tests\Feature\Facturacion;

use App\Models\Cliente;
use App\Models\Documento;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\SerieDocumento;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/**
 * La otra vida de una factura: mientras es borrador (docs/15, FAC-02).
 *
 * Un borrador se escribe, se cierra, se vuelve a abrir y se cambia entero. No
 * tiene número, no mueve inventario y no cuenta como venta. Al emitirse deja de
 * ser editable para siempre.
 */
class BorradorFacturaTest extends TestCase
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

    private function existencia(string $sku): float
    {
        return (float) Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', $sku)->value('existencia');
    }

    private function proximoNumero(): int
    {
        return (int) SerieDocumento::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('tipo', 'factura')->value('proximo_numero');
    }

    /** Dos panes de leche a 9.50 con 11.5%. */
    private function hoja(array $extra = []): array
    {
        return array_merge([
            'renglones' => [['producto' => $this->ulidProducto('PAN-001'), 'cantidad' => 2]],
        ], $extra);
    }

    private function guardar(array $datos = []): array
    {
        return $this->postJson('/v1/facturas/borradores', $this->hoja($datos))->assertCreated()->json();
    }

    // --- guardar -------------------------------------------------------------

    public function test_un_borrador_no_gasta_numero_ni_mueve_mercancia(): void
    {
        $this->entrar();
        $existenciaAntes = $this->existencia('PAN-001');
        $numeroAntes = $this->proximoNumero();

        $borrador = $this->guardar();

        $this->assertSame('borrador', $borrador['estado']);
        $this->assertNull($borrador['folio']);
        $this->assertTrue($borrador['editable']);
        // Los totales sí se calculan: se ve lo que va a costar.
        $this->assertSame('21.19', $borrador['total']);

        $this->assertSame($existenciaAntes, $this->existencia('PAN-001'));
        $this->assertSame($numeroAntes, $this->proximoNumero());
    }

    public function test_guarda_fecha_vencimiento_vendedor_referencia_y_nota(): void
    {
        $this->entrar();

        $borrador = $this->guardar([
            'fecha' => '2026-03-01',
            'vence_el' => '2026-03-31',
            'vendedor' => 'Emily Ortiz',
            'referencia' => 'OC-4471',
            'notas' => 'Entregar antes de las 10.',
        ]);

        $this->assertSame('2026-03-01', $borrador['fecha']);
        $this->assertSame('2026-03-31', $borrador['vence_el']);
        $this->assertSame('Emily Ortiz', $borrador['vendedor']);
        $this->assertSame('OC-4471', $borrador['referencia']);
        $this->assertSame('Entregar antes de las 10.', $borrador['notas']);
    }

    public function test_el_vencimiento_no_puede_ser_anterior_a_la_fecha(): void
    {
        $this->entrar();

        $this->postJson('/v1/facturas/borradores', $this->hoja([
            'fecha' => '2026-03-10',
            'vence_el' => '2026-03-01',
        ]))->assertStatus(422)->assertJsonValidationErrors('vence_el');
    }

    public function test_sin_vencimiento_lo_calcula_desde_la_fecha_de_la_hoja(): void
    {
        // "30 dias" contados desde la fecha del documento, no desde hoy (FAC-15).
        $this->entrar();
        $cliente = Cliente::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('terminos_pago', 'like', '%30%')->firstOrFail();

        $borrador = $this->guardar(['cliente' => $cliente->ulid, 'fecha' => '2026-03-01']);

        $this->assertSame('2026-03-31', $borrador['vence_el']);
    }

    public function test_un_borrador_puede_no_tener_renglones_todavia(): void
    {
        // Se empieza por el cliente y la fecha; los renglones vienen después.
        $this->entrar();

        $r = $this->postJson('/v1/facturas/borradores', ['renglones' => []])->assertCreated();

        $this->assertSame('0.00', $r->json('total'));
        $this->assertSame([], $r->json('renglones'));
    }

    // --- reabrir y reescribir ------------------------------------------------

    public function test_reescribir_un_borrador_no_crea_otro(): void
    {
        $this->entrar();
        $borrador = $this->guardar();
        $cuantos = Documento::on('pgsql_migrator')->withoutGlobalScope('empresa')->count();

        $r = $this->putJson("/v1/facturas/{$borrador['id']}", $this->hoja([
            'renglones' => [
                ['producto' => $this->ulidProducto('PAN-001'), 'cantidad' => 1],
                ['descripcion' => 'Decoración con nombre', 'detalle' => 'Letra azul', 'cantidad' => 1, 'precio' => '4.00'],
            ],
        ]))->assertOk();

        $this->assertSame($borrador['id'], $r->json('id'));
        $this->assertCount(2, $r->json('renglones'));
        $this->assertSame('Letra azul', $r->json('renglones.1.detalle'));
        $this->assertSame($cuantos, Documento::on('pgsql_migrator')->withoutGlobalScope('empresa')->count());
    }

    public function test_al_reabrirlo_trae_lo_que_hace_falta_para_seguir_escribiendo(): void
    {
        // Sin el ULID del producto y la tasa, la hoja no puede volver a
        // dibujar el renglón tal como se dejó.
        $this->entrar();
        $borrador = $this->guardar();

        $r = $this->getJson("/v1/facturas/{$borrador['id']}")->assertOk();

        $this->assertSame($this->ulidProducto('PAN-001'), $r->json('renglones.0.producto'));
        $this->assertSame('11.5', $r->json('renglones.0.tasa'));
        $this->assertEquals(2, $r->json('renglones.0.cantidad'));
        $this->assertSame('9.50', $r->json('renglones.0.precio'));
        $this->assertTrue($r->json('editable'));
    }

    // --- emitir --------------------------------------------------------------

    public function test_emitir_un_borrador_conserva_su_identificador(): void
    {
        // El enlace que alguien tenía abierto tiene que seguir sirviendo.
        $this->entrar();
        $borrador = $this->guardar();
        $existenciaAntes = $this->existencia('PAN-001');

        $r = $this->postJson("/v1/facturas/{$borrador['id']}/emitir", $this->hoja())->assertCreated();

        $this->assertSame($borrador['id'], $r->json('id'));
        $this->assertSame('emitida', $r->json('estado'));
        $this->assertSame('F-00001', $r->json('folio'));
        $this->assertFalse($r->json('editable'));
        $this->assertSame($existenciaAntes - 2, $this->existencia('PAN-001'));
    }

    public function test_al_emitir_se_guarda_lo_ultimo_que_se_escribio(): void
    {
        $this->entrar();
        $borrador = $this->guardar();

        $r = $this->postJson("/v1/facturas/{$borrador['id']}/emitir", $this->hoja([
            'renglones' => [['producto' => $this->ulidProducto('PAN-001'), 'cantidad' => 5]],
            'vendedor' => 'Gina Colón',
        ]))->assertCreated();

        $this->assertEquals(5, $r->json('renglones.0.cantidad'));
        $this->assertSame('Gina Colón', $r->json('vendedor'));
        $this->assertSame('52.96', $r->json('total'));   // 47.50 + 11.5%
    }

    public function test_una_factura_emitida_ya_no_se_reescribe(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/facturas', $this->hoja())->assertCreated()->json('id');

        $this->putJson("/v1/facturas/{$id}", $this->hoja())
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'no_es_borrador');
    }

    public function test_un_borrador_no_se_cobra_ni_se_anula(): void
    {
        $this->entrar();
        $borrador = $this->guardar();

        $this->postJson("/v1/facturas/{$borrador['id']}/cobrar", ['metodo' => 'efectivo', 'monto' => '5.00'])
            ->assertStatus(422);

        $this->postJson("/v1/facturas/{$borrador['id']}/anular", ['motivo' => 'Me equivoqué'])
            ->assertStatus(422);
    }

    // --- descartar -----------------------------------------------------------

    public function test_descartar_un_borrador_lo_borra_de_verdad(): void
    {
        $this->entrar();
        $borrador = $this->guardar();
        $numeroAntes = $this->proximoNumero();

        $this->deleteJson("/v1/facturas/{$borrador['id']}")->assertOk();

        $this->getJson("/v1/facturas/{$borrador['id']}")->assertStatus(404);
        // No gastó número: la serie sigue donde estaba (ARQ-08).
        $this->assertSame($numeroAntes, $this->proximoNumero());
    }

    public function test_una_factura_emitida_no_se_descarta(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/facturas', $this->hoja())->assertCreated()->json('id');

        $this->deleteJson("/v1/facturas/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'no_es_borrador');

        $this->assertSame(1, Documento::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('ulid', $id)->count());
    }

    // --- listado -------------------------------------------------------------

    public function test_los_borradores_no_cuentan_como_facturado(): void
    {
        // Es la cifra que se mira para saber cómo va el día: un borrador no es
        // una venta y no puede inflarla.
        $this->entrar();
        $this->postJson('/v1/facturas', $this->hoja())->assertCreated();
        $this->guardar();

        $r = $this->getJson('/v1/facturas')->assertOk();

        $this->assertSame(1, $r->json('resumen.cantidad'));
        $this->assertSame('21.19', $r->json('resumen.total'));
        $this->assertSame(1, $r->json('resumen.borradores'));
        $this->assertCount(2, $r->json('datos'));   // sí aparecen en la lista
    }

    public function test_se_pueden_listar_solo_los_borradores(): void
    {
        $this->entrar();
        $this->postJson('/v1/facturas', $this->hoja())->assertCreated();
        $this->guardar();

        $r = $this->getJson('/v1/facturas?estado=borrador')->assertOk();

        $this->assertCount(1, $r->json('datos'));
        $this->assertSame('borrador', $r->json('datos.0.estado'));
    }

    // --- aislamiento y permisos ----------------------------------------------

    public function test_no_se_alcanza_el_borrador_de_otra_empresa(): void
    {
        $this->entrar();
        $borrador = $this->guardar();

        $this->postJson('/v1/sesion', ['email' => 'carlos@innovacion.test', 'password' => DatabaseSeeder::CLAVE])->assertOk();

        $this->getJson("/v1/facturas/{$borrador['id']}")->assertStatus(404);
        $this->putJson("/v1/facturas/{$borrador['id']}", $this->hoja())->assertStatus(404);
        $this->deleteJson("/v1/facturas/{$borrador['id']}")->assertStatus(404);
    }

    public function test_una_empresa_suspendida_no_guarda_borradores(): void
    {
        $this->entrar('carlos@innovacion.test');

        $this->postJson('/v1/facturas/borradores', [
            'renglones' => [['descripcion' => 'Algo', 'cantidad' => 1, 'precio' => '1.00']],
        ])->assertStatus(423);
    }

    public function test_el_de_almacen_no_guarda_borradores(): void
    {
        $this->entrar('carmina@elalamo.test');

        $this->postJson('/v1/facturas/borradores', [
            'renglones' => [['descripcion' => 'Algo', 'cantidad' => 1, 'precio' => '1.00']],
        ])->assertStatus(403);
    }

    // --- membrete ------------------------------------------------------------

    public function test_el_membrete_trae_lo_que_la_hoja_imprime_arriba(): void
    {
        $this->entrar();

        $r = $this->getJson('/v1/empresa')->assertOk();

        $this->assertSame('Panadería El Álamo', $r->json('nombre'));
        $this->assertSame('11.5', $r->json('impuesto_tasa'));
        $this->assertSame('Estatal', $r->json('impuesto_desglose.0.nombre'));
        $this->assertSame('F-00001', $r->json('serie.proximo_folio'));
        $this->assertNotNull($r->json('direccion'));
    }

    public function test_el_membrete_no_deja_ver_el_de_otra_empresa(): void
    {
        $this->entrar('carlos@innovacion.test');

        $r = $this->getJson('/v1/empresa')->assertOk();

        $this->assertNotSame('Panadería El Álamo', $r->json('nombre'));
    }

    public function test_el_inventario_solo_se_mueve_al_emitir_no_al_guardar(): void
    {
        $this->entrar();
        $producto = Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'PAN-001')->firstOrFail();
        $movimientos = fn () => MovimientoInventario::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('producto_id', $producto->id)->count();

        $antes = $movimientos();
        $borrador = $this->guardar();
        $this->assertSame($antes, $movimientos());

        // Reescribirlo tres veces tampoco mueve nada.
        foreach ([1, 3, 2] as $cantidad) {
            $this->putJson("/v1/facturas/{$borrador['id']}", [
                'renglones' => [['producto' => $this->ulidProducto('PAN-001'), 'cantidad' => $cantidad]],
            ])->assertOk();
        }
        $this->assertSame($antes, $movimientos());

        $this->postJson("/v1/facturas/{$borrador['id']}/emitir", $this->hoja())->assertCreated();
        $this->assertSame($antes + 1, $movimientos());
    }
}
