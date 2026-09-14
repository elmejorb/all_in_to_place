<?php

namespace Tests\Feature\Catalogo;

use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/**
 * La matriz de docs/04-roles-y-permisos.md, verificada endpoint por endpoint.
 * Ocultar el botón en la interfaz no es un permiso (ROL-03, SEG-11).
 */
class PermisosCatalogoTest extends TestCase
{
    private function entrar(string $email): void
    {
        $this->postJson('/v1/sesion', ['email' => $email, 'password' => DatabaseSeeder::CLAVE])->assertOk();
    }

    public static function rolesQueVen(): array
    {
        return [
            'propietario' => ['pedro@elalamo.test'],
            'administrador' => ['marta@elalamo.test'],
            'gerente' => ['gina@elalamo.test'],
            'empleado de almacén' => ['carmina@elalamo.test'],
            'contador' => ['sonia@elalamo.test'],
        ];
    }

    public static function rolesQueNoVen(): array
    {
        return [
            'empleado de mostrador' => ['emily@elalamo.test'],
            'contratista' => ['julio@elalamo.test'],
        ];
    }

    /** @dataProvider rolesQueVen */
    public function test_estos_roles_ven_el_catalogo(string $email): void
    {
        $this->entrar($email);
        $this->getJson('/v1/suplidores')->assertOk();
    }

    /** @dataProvider rolesQueNoVen */
    public function test_estos_roles_no_ven_el_catalogo(string $email): void
    {
        $this->entrar($email);
        $this->getJson('/v1/suplidores')->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');
    }

    public function test_el_contador_lee_pero_no_escribe(): void
    {
        $this->entrar('sonia@elalamo.test');

        $this->getJson('/v1/suplidores')->assertOk()->assertJsonPath('permisos.editar', false);
        $this->postJson('/v1/suplidores', ['razon_social' => 'No debería crearse'])->assertStatus(403);
    }

    public function test_el_gerente_edita_pero_no_desactiva(): void
    {
        $this->entrar('gina@elalamo.test');

        $r = $this->getJson('/v1/suplidores')->assertOk();
        $this->assertTrue($r->json('permisos.editar'));
        $this->assertFalse($r->json('permisos.desactivar'));

        $this->postJson('/v1/suplidores', ['razon_social' => 'Creado por el gerente'])->assertCreated();

        $ulid = $this->getJson('/v1/suplidores?buscar=Creado por el gerente')->json('datos.0.id');
        $this->postJson('/v1/suplidores/'.$ulid.'/desactivar')->assertStatus(403);
    }

    public function test_el_empleado_de_almacen_ve_pero_no_crea(): void
    {
        $this->entrar('carmina@elalamo.test');

        $this->getJson('/v1/suplidores')->assertOk()->assertJsonPath('permisos.editar', false);
        $this->postJson('/v1/suplidores', ['razon_social' => 'No debería crearse'])->assertStatus(403);
    }

    public function test_una_empresa_suspendida_se_lee_pero_no_se_escribe(): void
    {
        // ADM-03: los datos siguen ahí y se pueden consultar y exportar.
        $this->entrar('carlos@innovacion.test');

        $this->getJson('/v1/suplidores')->assertOk()->assertJsonCount(1, 'datos');

        $this->postJson('/v1/suplidores', ['razon_social' => 'Durante la suspensión'])
            ->assertStatus(423)
            ->assertJsonPath('codigo', 'solo_lectura');
    }
}
