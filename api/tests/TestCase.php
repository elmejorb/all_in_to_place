<?php

namespace Tests;

use App\Soporte\ContextoRls;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    private static ?string $claseSembrada = null;
    private static bool $esquemaCreado = false;

    /**
     * Ponlo en true cuando la clase pruebe algo con estado que se arrastra
     * —la numeración de facturas, por ejemplo—: cada prueba arranca entonces
     * con el escenario intacto, a cambio de tardar un poco más.
     */
    protected bool $sembrarPorPrueba = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Las migraciones y las semillas corren con el rol dueño del esquema:
        // el rol de la aplicación no puede crear tablas ni empresas (SEG-38).
        if (! self::$esquemaCreado) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql_migrator', '--force' => true]);
            self::$esquemaCreado = true;
            self::$claseSembrada = null;
        }

        // Cada clase arranca con el escenario intacto: lo que crea una prueba no
        // puede cambiarle la cuenta a la siguiente.
        if ($this->sembrarPorPrueba || self::$claseSembrada !== static::class) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql_migrator', '--force' => true]);
            Artisan::call('db:seed', ['--force' => true]);
            self::$claseSembrada = static::class;
        }
    }

    /**
     * Cada petición simulada arranca sin contexto de aislamiento, igual que en
     * producción.
     *
     * Sin esto las pruebas heredan el contexto de la petición anterior —el
     * proceso es el mismo y la conexión también— y dan por bueno código que en
     * el navegador falla con 404. Pasó de verdad: el enlace de modelo de ruta
     * resolvía antes de que el middleware abriera el contexto.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        ContextoRls::limpiar();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
