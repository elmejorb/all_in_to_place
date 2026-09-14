<?php

namespace Tests\Feature\Catalogo;

use App\Models\Empresa;
use App\Models\Suplidor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuplidorTest extends TestCase
{
    private function entrar(string $email = 'pedro@elalamo.test'): void
    {
        $this->postJson('/v1/sesion', ['email' => $email, 'password' => DatabaseSeeder::CLAVE])->assertOk();
    }

    private function suplidorDe(string $empresa, string $razon): Suplidor
    {
        $empresaId = Empresa::on('pgsql_migrator')->where('nombre_comercial', $empresa)->value('id');

        return Suplidor::on('pgsql_migrator')
            ->withoutGlobalScope('empresa')
            ->where('empresa_id', $empresaId)
            ->where('razon_social', $razon)
            ->firstOrFail();
    }

    // --- listado --------------------------------------------------------

    public function test_lista_solo_los_activos_de_su_empresa(): void
    {
        $this->entrar();

        $r = $this->getJson('/v1/suplidores')->assertOk();

        // El Álamo tiene 5 suplidores, uno inactivo: por defecto se ven 4.
        $this->assertCount(4, $r->json('datos'));
        $this->assertSame('Azúcares Refinados PR', $r->json('datos.0.razon_social'));
        $this->assertTrue($r->json('permisos.editar'));
    }

    public function test_puede_ver_los_inactivos_si_los_pide(): void
    {
        $this->entrar();

        $this->getJson('/v1/suplidores?estado=inactivos')
            ->assertOk()
            ->assertJsonCount(1, 'datos')
            ->assertJsonPath('datos.0.razon_social', 'Suplidor Antiguo Cerrado');

        $this->getJson('/v1/suplidores?estado=todos')->assertOk()->assertJsonCount(5, 'datos');
    }

    public function test_busca_por_razon_social_vendedor_y_numero(): void
    {
        $this->entrar();

        $this->getJson('/v1/suplidores?buscar=harinas')->assertOk()->assertJsonCount(1, 'datos');
        $this->getJson('/v1/suplidores?buscar=MARGARITA')->assertOk()->assertJsonCount(1, 'datos');
        $this->getJson('/v1/suplidores?buscar=8891')->assertOk()->assertJsonCount(1, 'datos');
        $this->getJson('/v1/suplidores?buscar=nohaynada')->assertOk()->assertJsonCount(0, 'datos');
    }

    public function test_pagina_en_el_servidor_con_cursor_opaco(): void
    {
        $this->entrar();

        $r = $this->getJson('/v1/suplidores?por_pagina=2')->assertOk();
        $this->assertCount(2, $r->json('datos'));

        $cursor = $r->json('siguiente');
        $this->assertNotNull($cursor);
        // No es un número de página recorrible (ARQ-19).
        $this->assertFalse(is_numeric($cursor));

        $segunda = $this->getJson('/v1/suplidores?por_pagina=2&cursor='.urlencode($cursor))->assertOk();
        $this->assertNotSame($r->json('datos.0.id'), $segunda->json('datos.0.id'));

        // Nadie se lleva la tabla completa en una sola llamada (SEG-31).
        $this->getJson('/v1/suplidores?por_pagina=500')->assertStatus(422);
    }

    public function test_rechaza_ordenar_por_una_columna_no_permitida(): void
    {
        $this->entrar();

        // Lista blanca contra inyección por el nombre de columna (SEG-29).
        $this->getJson('/v1/suplidores?orden=password')->assertStatus(422);
        $this->getJson('/v1/suplidores?orden=razon_social')->assertOk();
    }

    // --- alta y edición -------------------------------------------------

    public function test_crea_un_suplidor_con_los_campos_separados(): void
    {
        $this->entrar();

        $r = $this->postJson('/v1/suplidores', [
            'razon_social' => 'Molinos del Norte Inc.',
            'nombre_comercial' => 'Molinos Norte',
            'numero_cliente' => '9001',
            'telefono' => '787-555-0000',
            'vendedor' => 'Ana Ruiz',
            'terminos_pago' => '30 días',
        ])->assertCreated();

        // La razón social y el contacto no se mezclan (SUP-01).
        $this->assertSame('Molinos del Norte Inc.', $r->json('razon_social'));
        $this->assertSame('Ana Ruiz', $r->json('vendedor'));
        $this->assertSame(26, strlen($r->json('id')));

        $this->getJson('/v1/suplidores?buscar=Molinos')->assertOk()->assertJsonCount(1, 'datos');
    }

    public function test_edita_un_suplidor_existente(): void
    {
        $this->entrar();
        $suplidor = $this->suplidorDe('Panadería El Álamo', 'Empaques y Cajas del Este');

        $this->putJson('/v1/suplidores/'.$suplidor->ulid, [
            'razon_social' => 'Empaques y Cajas del Este LLC',
            'vendedor' => 'Rosa Núñez',
            'numero_cliente' => '2210',
        ])->assertOk()->assertJsonPath('razon_social', 'Empaques y Cajas del Este LLC');
    }

    public function test_desactiva_en_vez_de_borrar(): void
    {
        $this->entrar();
        $suplidor = $this->suplidorDe('Panadería El Álamo', 'Azúcares Refinados PR');

        $this->postJson('/v1/suplidores/'.$suplidor->ulid.'/desactivar')
            ->assertOk()
            ->assertJsonPath('activo', false);

        // Sigue existiendo, con su historial (SUP-04).
        $this->assertNotNull(Suplidor::on('pgsql_migrator')->withoutGlobalScope('empresa')->find($suplidor->id));

        $this->postJson('/v1/suplidores/'.$suplidor->ulid.'/reactivar')->assertOk()->assertJsonPath('activo', true);
    }

    public function test_edita_con_el_mismo_cuerpo_que_envia_la_pantalla(): void
    {
        $this->entrar();

        $cuerpo = [
            'razon_social' => 'Suplidor de Prueba',
            'nombre_comercial' => null,
            'numero_cliente' => '9099',
            'telefono' => '787-555-1234',
            'email' => null,
            'vendedor' => 'Pruebas',
            'terminos_pago' => null,
        ];

        $ulid = $this->postJson('/v1/suplidores', $cuerpo)->assertCreated()->json('id');

        // La pantalla reenvía todos los campos, incluido su propio número de
        // cliente: la regla de unicidad tiene que ignorarse a sí misma.
        $r = $this->putJson('/v1/suplidores/'.$ulid, [...$cuerpo, 'terminos_pago' => '45 días']);

        if ($r->status() !== 200) {
            $this->fail('La edición respondió '.$r->status().': '.$r->getContent());
        }

        $r->assertJsonPath('terminos_pago', '45 días');
    }

    // --- lo que debe fallar (CAL-03) ------------------------------------

    public function test_exige_razon_social(): void
    {
        $this->entrar();

        $this->postJson('/v1/suplidores', ['vendedor' => 'Solo el contacto'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('razon_social');
    }

    public function test_no_repite_el_numero_de_cliente_dentro_de_la_empresa(): void
    {
        $this->entrar();

        $this->postJson('/v1/suplidores', ['razon_social' => 'Otra empresa', 'numero_cliente' => '4578'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero_cliente');
    }

    public function test_el_mismo_numero_si_vale_en_otra_empresa(): void
    {
        // El número lo asigna cada suplidor: es único por empresa, no global (SUP-02).
        $this->postJson('/v1/sesion', ['email' => 'luis@elalamo.test', 'password' => DatabaseSeeder::CLAVE])->assertOk();
        $santaMonica = Empresa::on('pgsql_migrator')->where('nombre_comercial', 'El Álamo Santa Mónica')->firstOrFail();
        $this->postJson('/v1/sesion/empresa', ['empresa' => $santaMonica->ulid])->assertOk();

        $this->postJson('/v1/suplidores', ['razon_social' => 'Repetido a propósito', 'numero_cliente' => '4578'])
            ->assertCreated();
    }

    public function test_valida_el_formato_del_telefono_y_del_correo(): void
    {
        $this->entrar();

        $this->postJson('/v1/suplidores', [
            'razon_social' => 'Con datos malos',
            'telefono' => 'llamar al de siempre',
            'email' => 'esto-no-es-correo',
        ])->assertStatus(422)->assertJsonValidationErrors(['telefono', 'email']);
    }

    public function test_descarta_los_campos_que_no_declara_el_esquema(): void
    {
        $this->entrar();

        $otra = Empresa::on('pgsql_migrator')->where('nombre_comercial', 'Innovación Digital')->firstOrFail();

        $r = $this->postJson('/v1/suplidores', [
            'razon_social' => 'Intento de colarse',
            'empresa_id' => $otra->id,      // no se acepta del cliente (SEG-14)
            'id' => 9999,
            'ulid' => 'AAAAAAAAAAAAAAAAAAAAAAAAAA',
        ])->assertCreated();

        $creado = Suplidor::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('razon_social', 'Intento de colarse')->firstOrFail();

        $this->assertNotSame($otra->id, $creado->empresa_id);
        $this->assertNotSame('AAAAAAAAAAAAAAAAAAAAAAAAAA', $creado->ulid);
    }

    public function test_sin_empresa_activa_no_hay_catalogo(): void
    {
        // Luis tiene dos empresas y no eligió ninguna al entrar.
        $this->postJson('/v1/sesion', ['email' => 'luis@elalamo.test', 'password' => DatabaseSeeder::CLAVE])->assertOk();

        $this->getJson('/v1/suplidores')->assertStatus(409)->assertJsonPath('codigo', 'sin_empresa');
    }

    public function test_sin_sesion_no_hay_catalogo(): void
    {
        $this->getJson('/v1/suplidores')->assertStatus(401);
    }
}
