<?php

namespace Tests\Feature\Caja;

use App\Models\HojaCuadre;
use App\Models\Producto;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La hoja de cuadre por HTTP: lo que se guarda, lo que se compara y lo que no
 * se puede hacer (CAJ-11 a CAJ-14).
 */
class HojaCuadreTest extends TestCase
{
    protected bool $sembrarPorPrueba = true;

    private function entrar(string $email = 'pedro@elalamo.test'): void
    {
        $this->postJson('/v1/sesion', ['email' => $email, 'password' => DatabaseSeeder::CLAVE])->assertOk();
    }

    private function ayer(): string
    {
        return Carbon::yesterday()->toDateString();
    }

    /**
     * El día y el turno tal como los cuenta la empresa. Calcularlos con el
     * reloj del servidor es lo que hacía fallar esta prueba: en Puerto Rico
     * son las once de la mañana cuando en el servidor son las cinco de la tarde.
     *
     * @return array{0: string, 1: string}
     */
    private function ahoraEnLaEmpresa(): array
    {
        $r = $this->getJson('/v1/cuadres')->assertOk();

        return [$r->json('hoy'), $r->json('turno_actual')];
    }

    /**
     * El turno de referencia: las mismas cifras que Luis metió en el sistema
     * actual para enseñarme los cálculos, así que los totales de aquí se pueden
     * cotejar con los de allá.
     */
    private function turno(array $extra = []): array
    {
        return array_merge([
            'fecha' => $this->ayer(),
            'turno' => 'am',
            'efectivo_inicial' => '5000.00',
            'ventas_lectura' => '150000.00',
            'efectivo_cambio' => '12.00',
            'tarjeta' => '100000.00',
            'ath_movil' => '154000.00',
            'gastos' => [
                ['descripcion' => 'Compra de agua', 'monto' => '150.00'],
            ],
        ], $extra);
    }

    // --- guardar -------------------------------------------------------------

    public function test_guarda_la_hoja_y_calcula_hasta_el_deposito(): void
    {
        $this->entrar();

        $r = $this->postJson('/v1/cuadres', $this->turno())->assertCreated();

        $this->assertSame('155000.00', $r->json('venta_y_cambio'));
        $this->assertSame('154988.00', $r->json('total_efectivo'));
        $this->assertSame('150.00', $r->json('gastos'));
        $this->assertSame('154838.00', $r->json('a_depositar'));
        $this->assertSame('408838.00', $r->json('total_ventas'));
        $this->assertCount(1, $r->json('gastos_detalle'));
        $this->assertSame('Pedro Rivera', $r->json('cuadro'));
    }

    public function test_los_totales_no_se_guardan_se_calculan(): void
    {
        // Así una hoja nunca puede mostrar un total que no corresponda a sus
        // propios números.
        $this->entrar();
        $id = $this->postJson('/v1/cuadres', $this->turno())->assertCreated()->json('id');

        $columnas = HojaCuadre::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('ulid', $id)->firstOrFail()->getAttributes();

        foreach (array_keys($columnas) as $columna) {
            $this->assertStringNotContainsString('deposit', $columna);
            $this->assertStringNotContainsString('total_efectivo', $columna);
        }
    }

    public function test_reescribir_una_hoja_reemplaza_sus_gastos(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/cuadres', $this->turno())->assertCreated()->json('id');

        $r = $this->putJson("/v1/cuadres/{$id}", $this->turno([
            'gastos' => [['descripcion' => 'Solo este', 'monto' => '12.50']],
        ]))->assertOk();

        $this->assertCount(1, $r->json('gastos_detalle'));
        $this->assertSame('12.50', $r->json('gastos'));
        $this->assertSame('154975.50', $r->json('a_depositar'));
    }

    public function test_una_hoja_sin_gastos_es_valida(): void
    {
        $this->entrar();

        $r = $this->postJson('/v1/cuadres', $this->turno(['gastos' => []]))->assertCreated();

        $this->assertSame('0.00', $r->json('gastos'));
        $this->assertSame('154988.00', $r->json('a_depositar'));
    }

    // --- lo que tiene que fallar ---------------------------------------------

    public function test_no_se_cuadra_dos_veces_el_mismo_turno(): void
    {
        $this->entrar();
        $this->postJson('/v1/cuadres', $this->turno())->assertCreated();

        $this->postJson('/v1/cuadres', $this->turno())
            ->assertStatus(422)
            ->assertJsonValidationErrors('turno');

        // Pero el otro turno del mismo día sí.
        $this->postJson('/v1/cuadres', $this->turno(['turno' => 'pm']))->assertCreated();
    }

    public function test_no_se_cuadra_un_turno_que_no_ha_pasado(): void
    {
        $this->entrar();

        $this->postJson('/v1/cuadres', $this->turno(['fecha' => Carbon::tomorrow()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fecha');
    }

    public function test_un_gasto_sin_descripcion_o_sin_valor_no_pasa(): void
    {
        $this->entrar();

        $this->postJson('/v1/cuadres', $this->turno([
            'gastos' => [['descripcion' => '', 'monto' => '10.00']],
        ]))->assertStatus(422)->assertJsonValidationErrors('gastos.0.descripcion');

        $this->postJson('/v1/cuadres', $this->turno([
            'gastos' => [['descripcion' => 'Algo', 'monto' => '0']],
        ]))->assertStatus(422)->assertJsonValidationErrors('gastos.0.monto');

        $this->postJson('/v1/cuadres', $this->turno([
            'gastos' => [['descripcion' => 'Algo', 'monto' => 'diez']],
        ]))->assertStatus(422)->assertJsonValidationErrors('gastos.0.monto');
    }

    public function test_no_se_admiten_cifras_negativas(): void
    {
        $this->entrar();

        $this->postJson('/v1/cuadres', $this->turno(['ventas_lectura' => '-50.00']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ventas_lectura');
    }

    // --- comparación con lo facturado ----------------------------------------

    public function test_compara_lo_escrito_con_lo_que_el_sistema_facturo(): void
    {
        $this->entrar();

        // Una venta de hoy por la mañana, cobrada en efectivo.
        $sku = Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'PAN-001')->value('ulid');
        $factura = $this->postJson('/v1/facturas', [
            'renglones' => [['producto' => $sku, 'cantidad' => 2]],
            'pagos' => [['metodo' => 'efectivo']],
        ])->assertCreated();

        $total = $factura->json('total');
        [$hoy, $turno] = $this->ahoraEnLaEmpresa();

        $r = $this->postJson('/v1/cuadres', $this->turno([
            'fecha' => $hoy,
            'turno' => $turno,
            'ventas_lectura' => $total,       // se escribe justo lo cobrado en efectivo
        ]))->assertCreated();

        $this->assertSame($total, $r->json('facturado.efectivo'));
        $this->assertTrue($r->json('comparacion.efectivo.cuadra'));
        $this->assertSame('0.00', $r->json('comparacion.efectivo.diferencia'));

        // La tarjeta no cuadra: se escribieron 100,000 y no se cobró ninguna.
        $this->assertFalse($r->json('comparacion.tarjeta.cuadra'));
        $this->assertSame('100000.00', $r->json('comparacion.tarjeta.diferencia'));
    }

    public function test_una_factura_anulada_no_cuenta_en_la_comparacion(): void
    {
        $this->entrar();
        $sku = Producto::on('pgsql_migrator')->withoutGlobalScope('empresa')->where('sku', 'PAN-001')->value('ulid');

        $id = $this->postJson('/v1/facturas', [
            'renglones' => [['producto' => $sku, 'cantidad' => 1]],
        ])->assertCreated()->json('id');

        $this->postJson("/v1/facturas/{$id}/anular", ['motivo' => 'Se devolvió'])->assertOk();

        [$hoy, $turno] = $this->ahoraEnLaEmpresa();
        $r = $this->getJson("/v1/cuadres/facturado?fecha={$hoy}&turno={$turno}")->assertOk();

        $this->assertSame('0.00', $r->json('ventas'));
        $this->assertSame(0, $r->json('facturas'));
    }

    public function test_el_turno_de_manana_y_el_de_tarde_no_se_mezclan(): void
    {
        $this->entrar();

        $manana = $this->getJson('/v1/cuadres/facturado?fecha='.$this->ayer().'&turno=am')->assertOk();
        $tarde = $this->getJson('/v1/cuadres/facturado?fecha='.$this->ayer().'&turno=pm')->assertOk();

        $this->assertSame('00:00', $manana->json('desde'));
        $this->assertSame('11:59', $manana->json('hasta'));
        $this->assertSame('12:00', $tarde->json('desde'));
        $this->assertSame('23:59', $tarde->json('hasta'));
    }

    // --- listado --------------------------------------------------------------

    public function test_el_listado_suma_lo_gastado_y_lo_depositado(): void
    {
        $this->entrar();
        $this->postJson('/v1/cuadres', $this->turno())->assertCreated();
        $this->postJson('/v1/cuadres', $this->turno(['turno' => 'pm']))->assertCreated();

        $r = $this->getJson('/v1/cuadres')->assertOk();

        $this->assertSame(2, $r->json('resumen.hojas'));
        $this->assertSame('300.00', $r->json('resumen.gastos'));
        $this->assertSame('309676.00', $r->json('resumen.a_depositar'));
    }

    public function test_el_listado_filtra_por_fechas(): void
    {
        $this->entrar();
        $this->postJson('/v1/cuadres', $this->turno())->assertCreated();

        $lejos = Carbon::today()->subMonths(2)->toDateString();
        $this->assertCount(0, $this->getJson("/v1/cuadres?desde={$lejos}&hasta={$lejos}")->assertOk()->json('datos'));
        $this->assertCount(1, $this->getJson('/v1/cuadres?desde='.$this->ayer().'&hasta='.$this->ayer())->assertOk()->json('datos'));
    }

    public function test_la_fecha_final_no_puede_ser_anterior_a_la_inicial(): void
    {
        $this->entrar();

        $this->getJson('/v1/cuadres?desde='.Carbon::today()->toDateString().'&hasta='.$this->ayer())
            ->assertStatus(422)
            ->assertJsonValidationErrors('hasta');
    }

    // --- el papel --------------------------------------------------------------

    public function test_la_hoja_se_imprime_en_pdf(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/cuadres', $this->turno())->assertCreated()->json('id');

        $r = $this->get("/v1/cuadres/{$id}/pdf")->assertOk();

        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('cuadre-'.$this->ayer().'-am.pdf', $r->headers->get('X-Nombre-Archivo'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
    }

    public function test_no_se_imprime_la_hoja_de_otra_empresa(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/cuadres', $this->turno())->assertCreated()->json('id');

        $this->entrar('carlos@innovacion.test');
        $this->get("/v1/cuadres/{$id}/pdf")->assertStatus(404);
    }

    // --- permisos y aislamiento -----------------------------------------------

    public function test_el_de_mostrador_cuadra_pero_solo_ve_lo_suyo(): void
    {
        // La matriz del documento 04: abre y cierra su propio turno, no el de otros.
        $this->entrar();
        $ajena = $this->postJson('/v1/cuadres', $this->turno())->assertCreated()->json('id');

        $this->entrar('emily@elalamo.test');
        $propia = $this->postJson('/v1/cuadres', $this->turno(['turno' => 'pm']))->assertCreated()->json('id');

        $listado = $this->getJson('/v1/cuadres')->assertOk();
        $this->assertCount(1, $listado->json('datos'));
        $this->assertSame($propia, $listado->json('datos.0.id'));
        $this->assertFalse($listado->json('permisos.ver_todas'));

        // La de Pedro ni se ve ni se abre.
        $this->getJson("/v1/cuadres/{$ajena}")->assertStatus(404);
        $this->putJson("/v1/cuadres/{$ajena}", $this->turno())->assertStatus(404);
    }

    public function test_el_contador_ve_todas_pero_no_cuadra(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/cuadres', $this->turno())->assertCreated()->json('id');

        $this->entrar('sonia@elalamo.test');

        $r = $this->getJson('/v1/cuadres')->assertOk();
        $this->assertCount(1, $r->json('datos'));
        $this->assertTrue($r->json('permisos.ver_todas'));
        $this->assertFalse($r->json('permisos.cuadrar'));

        $this->getJson("/v1/cuadres/{$id}")->assertOk();
        $this->postJson('/v1/cuadres', $this->turno(['turno' => 'pm']))->assertStatus(403);
        $this->putJson("/v1/cuadres/{$id}", $this->turno())->assertStatus(403);
    }

    public function test_el_de_almacen_no_toca_la_caja(): void
    {
        $this->entrar('carmina@elalamo.test');

        $this->getJson('/v1/cuadres')->assertStatus(403);
        $this->postJson('/v1/cuadres', $this->turno())->assertStatus(403);
    }

    public function test_no_se_alcanza_la_hoja_de_otra_empresa(): void
    {
        $this->entrar();
        $id = $this->postJson('/v1/cuadres', $this->turno())->assertCreated()->json('id');

        $this->entrar('carlos@innovacion.test');

        $this->getJson("/v1/cuadres/{$id}")->assertStatus(404);
        $this->assertCount(0, $this->getJson('/v1/cuadres')->assertOk()->json('datos'));
    }

    public function test_una_empresa_suspendida_consulta_pero_no_cuadra(): void
    {
        $this->entrar('carlos@innovacion.test');

        $this->getJson('/v1/cuadres')->assertOk();
        $this->postJson('/v1/cuadres', $this->turno())->assertStatus(423);
    }
}
