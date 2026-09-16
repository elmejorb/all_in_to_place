<?php

namespace Tests\Feature\Catalogo;

use App\Models\Cliente;
use App\Models\Empresa;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

class ClienteTest extends TestCase
{
    private function entrar(string $email = 'pedro@elalamo.test'): void
    {
        $this->postJson('/v1/sesion', ['email' => $email, 'password' => DatabaseSeeder::CLAVE])->assertOk();
    }

    private function clienteDe(string $empresa, string $nombre): Cliente
    {
        $empresaId = Empresa::on('pgsql_migrator')->where('nombre_comercial', $empresa)->value('id');

        return Cliente::on('pgsql_migrator')
            ->withoutGlobalScope('empresa')
            ->where('empresa_id', $empresaId)
            ->where('nombre', $nombre)
            ->firstOrFail();
    }

    // --- listado -----------------------------------------------------------

    public function test_lista_los_clientes_activos_de_su_empresa(): void
    {
        $this->entrar();

        $r = $this->getJson('/v1/clientes')->assertOk();

        $this->assertCount(5, $r->json('datos'));   // seis, uno inactivo
        $this->assertSame('Cafetería La Esquina', $r->json('datos.0.nombre'));
    }

    public function test_busca_por_nombre_identificacion_correo_y_telefono(): void
    {
        $this->entrar();

        $this->getJson('/v1/clientes?buscar=esquina')->assertOk()->assertJsonCount(1, 'datos');
        $this->getJson('/v1/clientes?buscar=660-98')->assertOk()->assertJsonCount(1, 'datos');
        $this->getJson('/v1/clientes?buscar=jrolon')->assertOk()->assertJsonCount(1, 'datos');

        // El teléfono se encuentra aunque se escriba con otro formato.
        $this->getJson('/v1/clientes?buscar=7875554040')->assertOk()->assertJsonCount(1, 'datos');
        $this->getJson('/v1/clientes?buscar=(787) 555-4040')->assertOk()->assertJsonCount(1, 'datos');
    }

    public function test_filtra_por_tipo_y_por_credito(): void
    {
        $this->entrar();

        $this->getJson('/v1/clientes?tipo=persona')->assertOk()->assertJsonCount(2, 'datos');
        $this->getJson('/v1/clientes?tipo=empresa')->assertOk()->assertJsonCount(3, 'datos');

        $conCredito = $this->getJson('/v1/clientes?con_credito=1')->assertOk()->json('datos');
        $this->assertCount(3, $conCredito);
        $this->assertTrue(collect($conCredito)->every(fn ($c) => $c['tiene_credito']));
    }

    public function test_muestra_la_exencion_con_su_certificado(): void
    {
        $this->entrar();

        $colegio = $this->getJson('/v1/clientes?buscar=Colegio')->assertOk()->json('datos.0');

        $this->assertTrue($colegio['exento']);
        $this->assertSame('EXE-2024-114', $colegio['certificado_exencion']);
        $this->assertSame('1500.00', $colegio['limite_credito']);
    }

    // --- alta y edición ----------------------------------------------------

    public function test_crea_un_cliente_al_vuelo_con_solo_el_nombre(): void
    {
        // CLI-02: en la caja no hay tiempo de llenar una ficha entera.
        $this->entrar();

        $r = $this->postJson('/v1/clientes', ['nombre' => 'Cliente de mostrador'])->assertCreated();

        $this->assertSame('persona', $r->json('tipo'));
        $this->assertFalse($r->json('exento'));
        $this->assertSame('0.00', $r->json('limite_credito'));
        $this->assertSame(26, strlen($r->json('id')));
    }

    public function test_crea_un_cliente_completo(): void
    {
        $this->entrar();

        $r = $this->postJson('/v1/clientes', [
            'nombre' => 'Restaurante El Ancla',
            'tipo' => 'empresa',
            'identificacion' => '660-77-8899',
            'telefono' => '787-555-9090',
            'email' => 'compras@ancla.test',
            'direccion' => 'Calle Marina 14, Cataño',
            'exento' => true,
            'certificado_exencion' => 'EXE-2025-33',
            'terminos_pago' => '30 dias',
            'limite_credito' => '2500.50',
        ])->assertCreated();

        $this->assertSame('2500.50', $r->json('limite_credito'));
        $this->assertTrue($r->json('tiene_credito'));
        $this->assertSame('EXE-2025-33', $r->json('certificado_exencion'));
    }

    public function test_al_quitar_la_exencion_se_va_el_certificado(): void
    {
        // Un número de certificado suelto en la ficha se acaba usando por error.
        $this->entrar();
        $colegio = $this->clienteDe('Panadería El Álamo', 'Colegio San Antonio');

        $this->putJson('/v1/clientes/'.$colegio->ulid, [
            'nombre' => 'Colegio San Antonio',
            'exento' => false,
        ])->assertOk()
            ->assertJsonPath('exento', false)
            ->assertJsonPath('certificado_exencion', null);
    }

    public function test_desactiva_en_vez_de_borrar(): void
    {
        $this->entrar();
        $cliente = $this->clienteDe('Panadería El Álamo', 'María Fernández');

        $this->postJson('/v1/clientes/'.$cliente->ulid.'/desactivar')->assertOk()->assertJsonPath('activo', false);
        $this->assertNotNull(Cliente::on('pgsql_migrator')->withoutGlobalScope('empresa')->find($cliente->id));

        $this->postJson('/v1/clientes/'.$cliente->ulid.'/reactivar')->assertOk()->assertJsonPath('activo', true);
    }

    // --- lo que debe fallar (CAL-03) ---------------------------------------

    public function test_un_cliente_exento_sin_certificado_no_se_guarda(): void
    {
        $this->entrar();

        $this->postJson('/v1/clientes', ['nombre' => 'Exento sin papeles', 'exento' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('certificado_exencion');
    }

    public function test_no_repite_la_identificacion_dentro_de_la_empresa(): void
    {
        $this->entrar();

        $this->postJson('/v1/clientes', ['nombre' => 'Otro', 'identificacion' => '660-12-3456'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identificacion');
    }

    public function test_valida_telefono_correo_y_limite_de_credito(): void
    {
        $this->entrar();

        $this->postJson('/v1/clientes', [
            'nombre' => 'Con datos malos',
            'telefono' => 'el de siempre',
            'email' => 'no-es-correo',
        ])->assertStatus(422)->assertJsonValidationErrors(['telefono', 'email']);

        $this->postJson('/v1/clientes', ['nombre' => 'Credito raro', 'limite_credito' => 'bastante'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('limite_credito');
    }

    public function test_exige_nombre(): void
    {
        $this->entrar();

        $this->postJson('/v1/clientes', ['telefono' => '787-555-0000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre');
    }

    public function test_no_alcanza_un_cliente_de_otra_empresa(): void
    {
        $ajeno = $this->clienteDe('Innovación Digital', 'Alcaldía de Planeta Rica');

        $this->entrar();

        $this->getJson('/v1/clientes?buscar=Alcaldía')->assertOk()->assertJsonCount(0, 'datos');
        $this->putJson('/v1/clientes/'.$ajeno->ulid, ['nombre' => 'Secuestrado'])->assertStatus(404);
        $this->postJson('/v1/clientes/'.$ajeno->ulid.'/desactivar')->assertStatus(404);

        $this->assertSame('Alcaldía de Planeta Rica', $ajeno->fresh()->nombre);
    }

    // --- permisos por rol ---------------------------------------------------

    public function test_el_de_mostrador_ve_y_crea_clientes(): void
    {
        // Sin esto no puede facturar a alguien que llega por primera vez (CLI-02).
        $this->entrar('emily@elalamo.test');

        $this->getJson('/v1/clientes')->assertOk()->assertJsonPath('permisos.editar', true);
        $this->postJson('/v1/clientes', ['nombre' => 'Creado en caja'])->assertCreated();
    }

    public function test_el_de_almacen_no_tiene_nada_que_hacer_en_clientes(): void
    {
        $this->entrar('carmina@elalamo.test');

        $this->getJson('/v1/clientes')->assertStatus(403);
        $this->postJson('/v1/clientes', ['nombre' => 'No deberia'])->assertStatus(403);
    }

    public function test_el_contador_lee_pero_no_escribe(): void
    {
        $this->entrar('sonia@elalamo.test');

        $this->getJson('/v1/clientes')->assertOk()->assertJsonPath('permisos.editar', false);
        $this->postJson('/v1/clientes', ['nombre' => 'No deberia'])->assertStatus(403);
    }

    public function test_una_empresa_suspendida_lee_pero_no_escribe(): void
    {
        $this->entrar('carlos@innovacion.test');

        $this->getJson('/v1/clientes')->assertOk()->assertJsonCount(1, 'datos');
        $this->postJson('/v1/clientes', ['nombre' => 'Durante la suspension'])->assertStatus(423);
    }
}
