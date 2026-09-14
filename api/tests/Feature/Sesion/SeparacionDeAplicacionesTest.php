<?php

namespace Tests\Feature\Sesion;

use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/**
 * ARQ-03: una sesión no cruza de una aplicación a la otra.
 */
class SeparacionDeAplicacionesTest extends TestCase
{
    public function test_la_sesion_de_la_consola_no_sirve_en_la_app_de_empresa(): void
    {
        $this->postJson('/v1/admin/sesion', [
            'email' => 'laura@aiop.test',
            'password' => DatabaseSeeder::CLAVE,
        ])->assertOk()->assertJsonPath('usuario.rol', 'superadmin');

        $this->getJson('/v1/admin/yo')->assertOk();

        // Misma sesión del navegador, otra aplicación: no pasa.
        $this->getJson('/v1/yo')->assertStatus(401);
    }

    public function test_la_sesion_de_empresa_no_sirve_en_la_consola(): void
    {
        $this->postJson('/v1/sesion', [
            'email' => 'pedro@elalamo.test',
            'password' => DatabaseSeeder::CLAVE,
        ])->assertOk();

        $this->getJson('/v1/admin/yo')->assertStatus(401);
    }

    public function test_un_usuario_de_empresa_no_entra_por_la_consola(): void
    {
        $this->postJson('/v1/admin/sesion', [
            'email' => 'pedro@elalamo.test',
            'password' => DatabaseSeeder::CLAVE,
        ])->assertStatus(422);
    }

    public function test_un_usuario_de_plataforma_no_entra_por_la_app_de_empresa(): void
    {
        $this->postJson('/v1/sesion', [
            'email' => 'laura@aiop.test',
            'password' => DatabaseSeeder::CLAVE,
        ])->assertStatus(422);
    }

    public function test_cada_aplicacion_usa_su_propia_cookie_de_sesion(): void
    {
        $this->postJson('/v1/sesion', ['email' => 'pedro@elalamo.test', 'password' => DatabaseSeeder::CLAVE]);
        $this->assertSame('aiop_empresa_sesion', config('session.cookie'));

        $this->postJson('/v1/admin/sesion', ['email' => 'laura@aiop.test', 'password' => DatabaseSeeder::CLAVE]);
        $this->assertSame('aiop_consola_sesion', config('session.cookie'));
    }
}
