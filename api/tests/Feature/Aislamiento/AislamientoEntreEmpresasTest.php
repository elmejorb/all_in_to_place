<?php

namespace Tests\Feature\Aislamiento;

use App\Models\Empresa;
use App\Models\Membresia;
use App\Models\Suplidor;
use App\Models\Usuario;
use App\Soporte\ContextoRls;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RNF-07: por cada camino, un intento de alcanzar datos de otra empresa.
 * Si alguna de estas pruebas falla, no se despliega.
 */
class AislamientoEntreEmpresasTest extends TestCase
{
    public function test_sin_contexto_la_conexion_de_la_app_no_ve_ninguna_empresa(): void
    {
        ContextoRls::limpiar();

        $this->assertSame(0, (int) DB::connection('pgsql')->select('select count(*) c from empresa')[0]->c);
        $this->assertSame(0, (int) DB::connection('pgsql')->select('select count(*) c from membresia')[0]->c);

        // Y la conexión dueña sí las ve: el dato está, lo que cambia es quién pregunta.
        $this->assertSame(3, (int) DB::connection('pgsql_migrator')->select('select count(*) c from empresa')[0]->c);
    }

    public function test_con_contexto_solo_ve_su_empresa_aunque_pida_otra_por_id(): void
    {
        $alamo = Empresa::on('pgsql_migrator')->where('nombre_comercial', 'Panadería El Álamo')->firstOrFail();
        $otra = Empresa::on('pgsql_migrator')->where('nombre_comercial', 'Innovación Digital')->firstOrFail();

        ContextoRls::limpiar();
        ContextoRls::fijar(ContextoRls::EMPRESA, $alamo->id);

        $this->assertSame(1, Empresa::on('pgsql')->count());

        // La consulta pide explícitamente la otra empresa y la base no la entrega.
        $this->assertNull(Empresa::on('pgsql')->find($otra->id));
        $this->assertNull(Empresa::on('pgsql')->where('ulid', $otra->ulid)->first());
    }

    public function test_la_app_de_empresa_no_alcanza_los_usuarios_de_la_consola(): void
    {
        ContextoRls::limpiar();

        // Sin política para aiop_app sobre usuario_plataforma (ARQ-03).
        $this->assertSame(0, (int) DB::connection('pgsql')->select('select count(*) c from usuario_plataforma')[0]->c);
        $this->assertSame(2, (int) DB::connection('pgsql_consola')->select('select count(*) c from usuario_plataforma')[0]->c);
    }

    public function test_la_app_no_puede_borrar_ni_crear_empresas(): void
    {
        ContextoRls::limpiar();

        $afectadas = DB::connection('pgsql')->delete('delete from empresa');
        $this->assertSame(0, $afectadas);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::connection('pgsql')->insert(
            "insert into empresa (ulid, nombre_legal, created_at, updated_at) values ('01JZZZZZZZZZZZZZZZZZZZZZZZ', 'Pirata', now(), now())"
        );
    }

    public function test_no_puede_cambiar_a_una_empresa_donde_no_tiene_membresia(): void
    {
        $ajena = Empresa::on('pgsql_migrator')->where('nombre_comercial', 'Innovación Digital')->firstOrFail();

        $this->postJson('/v1/sesion', ['email' => 'pedro@elalamo.test', 'password' => DatabaseSeeder::CLAVE])->assertOk();

        // Responde "no encontrada", no "sin permiso": no confirma que exista (ARQ-21).
        $this->postJson('/v1/sesion/empresa', ['empresa' => $ajena->ulid])
            ->assertStatus(404)
            ->assertJsonPath('errors.empresa.0', 'No encontrada.');
    }

    public function test_la_membresia_se_revisa_en_cada_peticion(): void
    {
        $luis = Usuario::on('pgsql_migrator')->where('email', 'luis@elalamo.test')->firstOrFail();
        $santaMonica = Empresa::on('pgsql_migrator')->where('nombre_comercial', 'El Álamo Santa Mónica')->firstOrFail();

        $this->postJson('/v1/sesion', ['email' => 'luis@elalamo.test', 'password' => DatabaseSeeder::CLAVE])->assertOk();
        $this->postJson('/v1/sesion/empresa', ['empresa' => $santaMonica->ulid])
            ->assertOk()
            ->assertJsonPath('empresa_activa.nombre', 'El Álamo Santa Mónica');

        // Le revocan la membresía con la sesión abierta (ROL-05).
        Membresia::on('pgsql_migrator')
            ->where('usuario_id', $luis->id)
            ->where('empresa_id', $santaMonica->id)
            ->update(['activa' => false]);

        $this->getJson('/v1/yo')->assertOk()->assertJsonPath('empresa_activa', null);

        Membresia::on('pgsql_migrator')
            ->where('usuario_id', $luis->id)
            ->where('empresa_id', $santaMonica->id)
            ->update(['activa' => true]);
    }

    public function test_el_catalogo_de_otra_empresa_no_se_alcanza_ni_con_su_identificador(): void
    {
        $ajeno = Suplidor::on('pgsql_migrator')->withoutGlobalScope('empresa')
            ->where('razon_social', 'Papelería Planeta Rica S.A.S.')->firstOrFail();

        $this->postJson('/v1/sesion', ['email' => 'pedro@elalamo.test', 'password' => DatabaseSeeder::CLAVE])->assertOk();

        // Existe, es de otra empresa, y responde "no encontrado" (ARQ-21).
        $this->getJson('/v1/suplidores?buscar=Papelería')->assertOk()->assertJsonCount(0, 'datos');
        $this->putJson('/v1/suplidores/'.$ajeno->ulid, ['razon_social' => 'Secuestrado'])->assertStatus(404);
        $this->postJson('/v1/suplidores/'.$ajeno->ulid.'/desactivar')->assertStatus(404);

        $this->assertSame('Papelería Planeta Rica S.A.S.', $ajeno->fresh()->razon_social);
    }

    public function test_la_consola_no_puede_leer_el_catalogo_de_ninguna_empresa(): void
    {
        // Sin política sobre tablas de negocio, el motor le niega los datos (ADM-08).
        $this->assertSame(0, (int) DB::connection('pgsql_consola')->select('select count(*) c from suplidor')[0]->c);
        $this->assertGreaterThan(0, (int) DB::connection('pgsql_migrator')->select('select count(*) c from suplidor')[0]->c);
    }

    public function test_ninguna_respuesta_expone_el_id_interno(): void
    {
        $r = $this->postJson('/v1/sesion', ['email' => 'pedro@elalamo.test', 'password' => DatabaseSeeder::CLAVE])->assertOk();

        $json = $r->getContent();
        $this->assertStringNotContainsString('"id":1', $json);
        $this->assertStringNotContainsString('empresa_id', $json);
        $this->assertStringNotContainsString('usuario_id', $json);
        $this->assertStringNotContainsString('password', $json);
    }
}
