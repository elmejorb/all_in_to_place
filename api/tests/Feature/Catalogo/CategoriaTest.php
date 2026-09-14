<?php

namespace Tests\Feature\Catalogo;

use App\Models\Categoria;
use App\Models\Empresa;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

class CategoriaTest extends TestCase
{
    private function entrar(string $email = 'pedro@elalamo.test'): void
    {
        $this->postJson('/v1/sesion', ['email' => $email, 'password' => DatabaseSeeder::CLAVE])->assertOk();
    }

    private function categoriaDe(string $empresa, string $nombre): Categoria
    {
        $empresaId = Empresa::on('pgsql_migrator')->where('nombre_comercial', $empresa)->value('id');

        return Categoria::on('pgsql_migrator')
            ->withoutGlobalScope('empresa')
            ->where('empresa_id', $empresaId)
            ->where('nombre', $nombre)
            ->firstOrFail();
    }

    public function test_lista_las_categorias_de_su_empresa(): void
    {
        $this->entrar();

        $r = $this->getJson('/v1/categorias')->assertOk();

        $this->assertCount(4, $r->json('datos'));
        $this->assertSame('Artesanal', $r->json('datos.0.nombre'));
        $this->assertContains('rosa', $r->json('colores'));
    }

    public function test_busca_por_nombre_y_descripcion(): void
    {
        $this->entrar();

        $this->getJson('/v1/categorias?buscar=dulces')->assertOk()->assertJsonCount(1, 'datos');
        $this->getJson('/v1/categorias?buscar=horneados')->assertOk()->assertJsonCount(1, 'datos');
        $this->getJson('/v1/categorias?buscar=nohay')->assertOk()->assertJsonCount(0, 'datos');
    }

    public function test_crea_una_categoria_con_color_de_la_paleta(): void
    {
        $this->entrar();

        $this->postJson('/v1/categorias', ['nombre' => 'Congelados', 'color' => 'azul'])
            ->assertCreated()
            ->assertJsonPath('nombre', 'Congelados')
            ->assertJsonPath('color', 'azul');
    }

    public function test_edita_una_categoria(): void
    {
        $this->entrar();
        $categoria = $this->categoriaDe('Panadería El Álamo', 'Bebidas');

        $this->putJson('/v1/categorias/'.$categoria->ulid, [
            'nombre' => 'Bebidas frías',
            'descripcion' => 'Jugos y refrescos',
            'color' => 'turquesa',
        ])->assertOk()->assertJsonPath('nombre', 'Bebidas frías');
    }

    public function test_cuenta_los_productos_de_cada_categoria(): void
    {
        $this->entrar();

        $pasteleria = collect($this->getJson('/v1/categorias')->json('datos'))
            ->firstWhere('nombre', 'Pastelería');

        // Incluye el descontinuado: si no, borrarla lo dejaría huérfano (CAT-02).
        $this->assertSame(5, $pasteleria['productos']);
    }

    public function test_una_categoria_vacia_se_borra(): void
    {
        $this->entrar();

        $ulid = $this->postJson('/v1/categorias', ['nombre' => 'Vacía y de paso'])->assertCreated()->json('id');

        $this->deleteJson('/v1/categorias/'.$ulid)->assertOk()->assertJsonPath('reasignados', 0);
        $this->getJson('/v1/categorias?buscar=Vacía')->assertOk()->assertJsonCount(0, 'datos');
    }

    public function test_una_categoria_con_productos_no_se_borra_sin_decir_a_donde_van(): void
    {
        $this->entrar();
        $pasteleria = $this->categoriaDe('Panadería El Álamo', 'Pastelería');

        $this->deleteJson('/v1/categorias/'.$pasteleria->ulid)
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'categoria_en_uso')
            ->assertJsonPath('productos', 5);

        // Sigue ahí, con sus productos.
        $this->assertNotNull($pasteleria->fresh());
    }

    public function test_reasigna_los_productos_al_borrar(): void
    {
        // Con categorías propias: una prueba no puede dejar sin datos a la siguiente.
        $this->entrar();

        $origen = $this->postJson('/v1/categorias', ['nombre' => 'De paso con productos'])->json('id');
        $this->postJson('/v1/productos', ['nombre' => 'Producto de paso', 'categoria' => $origen])->assertCreated();

        $dulces = $this->categoriaDe('Panadería El Álamo', 'Dulces');

        $this->deleteJson('/v1/categorias/'.$origen, ['reasignar_a' => $dulces->ulid])
            ->assertOk()
            ->assertJsonPath('reasignados', 1);

        $categorias = collect($this->getJson('/v1/categorias')->json('datos'));
        $this->assertNull($categorias->firstWhere('nombre', 'De paso con productos'));
        $this->assertSame(3, $categorias->firstWhere('nombre', 'Dulces')['productos']);   // 2 suyos + 1 heredado

        // El producto quedó en la categoría destino, no huérfano.
        $this->assertSame('Dulces', $this->getJson('/v1/productos?buscar=Producto de paso')->json('datos.0.categoria.nombre'));
    }

    public function test_no_reasigna_a_una_categoria_de_otra_empresa(): void
    {
        $this->entrar();

        $origen = $this->postJson('/v1/categorias', ['nombre' => 'Intento de fuga'])->json('id');
        $this->postJson('/v1/productos', ['nombre' => 'Producto atrapado', 'categoria' => $origen])->assertCreated();

        $ajena = $this->categoriaDe('Innovación Digital', 'Papelería');

        $this->deleteJson('/v1/categorias/'.$origen, ['reasignar_a' => $ajena->ulid])->assertStatus(404);

        // Ni se borró la categoría ni se movió el producto a la otra empresa.
        $this->getJson('/v1/categorias?buscar=Intento de fuga')->assertOk()->assertJsonCount(1, 'datos');
    }

    // --- lo que debe fallar (CAL-03) ------------------------------------

    public function test_no_repite_el_nombre_dentro_de_la_empresa(): void
    {
        $this->entrar();

        $this->postJson('/v1/categorias', ['nombre' => 'Dulces'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre');

        // Ni cambiando las mayúsculas.
        $this->postJson('/v1/categorias', ['nombre' => 'dULCES'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre');
    }

    public function test_el_mismo_nombre_si_vale_en_otra_empresa(): void
    {
        // "Pastelería" existe en las dos panaderías y eso está bien (CAT-01).
        $alamo = $this->categoriaDe('Panadería El Álamo', 'Pastelería');
        $santaMonica = $this->categoriaDe('El Álamo Santa Mónica', 'Pastelería');

        $this->assertNotSame($alamo->id, $santaMonica->id);
        $this->assertNotSame($alamo->empresa_id, $santaMonica->empresa_id);
    }

    public function test_rechaza_un_color_fuera_de_la_paleta(): void
    {
        $this->entrar();

        $this->postJson('/v1/categorias', ['nombre' => 'Con color raro', 'color' => '#ff00ff'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('color');
    }

    public function test_exige_nombre(): void
    {
        $this->entrar();

        $this->postJson('/v1/categorias', ['descripcion' => 'Sin nombre'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre');
    }

    public function test_no_alcanza_una_categoria_de_otra_empresa(): void
    {
        $ajena = $this->categoriaDe('Innovación Digital', 'Papelería');

        $this->entrar();

        $this->getJson('/v1/categorias?buscar=Papelería')->assertOk()->assertJsonCount(0, 'datos');
        $this->putJson('/v1/categorias/'.$ajena->ulid, ['nombre' => 'Secuestrada'])->assertStatus(404);

        $this->assertSame('Papelería', $ajena->fresh()->nombre);
    }

    public function test_el_contador_no_puede_crear_categorias(): void
    {
        $this->entrar('sonia@elalamo.test');

        $this->getJson('/v1/categorias')->assertOk();
        $this->postJson('/v1/categorias', ['nombre' => 'No debería crearse'])->assertStatus(403);
    }
}
