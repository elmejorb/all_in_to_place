<?php

namespace Tests\Feature\Sesion;

use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

class InicioSesionEmpresaTest extends TestCase
{
    public function test_entra_y_recibe_su_empresa_cuando_solo_tiene_una(): void
    {
        $r = $this->postJson('/v1/sesion', [
            'email' => 'pedro@elalamo.test',
            'password' => DatabaseSeeder::CLAVE,
        ]);

        $r->assertOk()
            ->assertJsonPath('autenticado', true)
            ->assertJsonPath('usuario.email', 'pedro@elalamo.test')
            ->assertJsonPath('empresa_activa.nombre', 'Panadería El Álamo')
            ->assertJsonPath('empresa_activa.rol', 'propietario')
            ->assertJsonPath('empresa_activa.pais', 'PR');

        // El identificador que sale es el ULID, nunca el id interno (ARQ-13).
        $this->assertSame(26, strlen($r->json('usuario.id')));
        $this->assertSame(26, strlen($r->json('empresa_activa.id')));
    }

    public function test_con_dos_empresas_no_elige_ninguna_y_ofrece_las_dos(): void
    {
        $r = $this->postJson('/v1/sesion', [
            'email' => 'luis@elalamo.test',
            'password' => DatabaseSeeder::CLAVE,
        ]);

        $r->assertOk()
            ->assertJsonPath('empresa_activa', null)
            ->assertJsonCount(2, 'empresas');
    }

    public function test_la_empresa_suspendida_entra_en_solo_lectura(): void
    {
        $this->postJson('/v1/sesion', [
            'email' => 'carlos@innovacion.test',
            'password' => DatabaseSeeder::CLAVE,
        ])
            ->assertOk()
            ->assertJsonPath('empresa_activa.estado', 'suspendida')
            ->assertJsonPath('empresa_activa.solo_lectura', true)
            ->assertJsonPath('empresa_activa.moneda', 'COP');
    }

    // --- lo que debe fallar (CAL-03) ------------------------------------

    public function test_clave_incorrecta_no_entra(): void
    {
        $this->postJson('/v1/sesion', [
            'email' => 'pedro@elalamo.test',
            'password' => 'incorrecta',
        ])->assertStatus(422);

        $this->assertGuest('empresa');
    }

    public function test_correo_inexistente_responde_igual_que_clave_incorrecta(): void
    {
        $existente = $this->postJson('/v1/sesion', ['email' => 'pedro@elalamo.test', 'password' => 'mala'])->json('message');
        $inexistente = $this->postJson('/v1/sesion', ['email' => 'nadie@ninguna.test', 'password' => 'mala'])->json('message');

        // No revela si el correo está registrado (SEG-08).
        $this->assertSame($existente, $inexistente);
    }

    public function test_usuario_desactivado_no_entra(): void
    {
        $this->postJson('/v1/sesion', [
            'email' => 'inactivo@elalamo.test',
            'password' => DatabaseSeeder::CLAVE,
        ])->assertStatus(422);

        $this->assertGuest('empresa');
    }

    public function test_usuario_sin_empresa_no_entra(): void
    {
        $this->postJson('/v1/sesion', [
            'email' => 'sinempresa@aiop.test',
            'password' => DatabaseSeeder::CLAVE,
        ])->assertStatus(403);

        // La sesión se invalida: no queda a medio entrar (ADM-20).
        $this->assertGuest('empresa');
    }

    public function test_bloquea_tras_cinco_intentos_fallidos(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/sesion', ['email' => 'pedro@elalamo.test', 'password' => 'mala'])->assertStatus(422);
        }

        $this->postJson('/v1/sesion', ['email' => 'pedro@elalamo.test', 'password' => 'mala'])->assertStatus(429);

        // Y sigue bloqueado aunque la clave sea la correcta (SEG-05).
        $this->postJson('/v1/sesion', ['email' => 'pedro@elalamo.test', 'password' => DatabaseSeeder::CLAVE])->assertStatus(429);
    }

    public function test_sin_sesion_no_hay_datos(): void
    {
        // Preguntar por la sesión es público y contesta que no hay.
        $this->getJson('/v1/sesion')
            ->assertOk()
            ->assertJsonPath('autenticado', false)
            ->assertJsonMissingPath('usuario');

        // Cualquier endpoint con datos sí exige sesión (SEG-11).
        $this->getJson('/v1/yo')->assertStatus(401);
        $this->postJson('/v1/sesion/empresa', ['empresa' => str_repeat('A', 26)])->assertStatus(401);
    }
}
