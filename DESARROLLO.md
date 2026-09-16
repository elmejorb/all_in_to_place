# Ambiente de desarrollo

Lo que hay que tener andando para trabajar en el proyecto, y cómo se probó.

## Lo que ya está instalado en esta máquina

| Pieza | Versión | Dónde |
|---|---|---|
| PHP | 8.2.12 | XAMPP, `C:\xampp\php` |
| Composer | 2.8.12 | global |
| Node | 22.19 | global |
| pnpm | 12.3 | instalado con `npm i -g pnpm` |
| PostgreSQL | 17.6 | binarios portables en `C:\Users\LUIS_FDO\pgsql` |
| Playwright + Chromium | 1.x | dependencia del repositorio |

No hace falta Docker ni instalar PostgreSQL como servicio: se usan los binarios
oficiales descomprimidos, con su propio clúster en `C:\Users\LUIS_FDO\pgsql\data`
y puerto **5433**, para no chocar con nada más de la máquina.

Se habilitaron las extensiones `pdo_pgsql` y `pgsql` en `C:\xampp\php\php.ini`
(hay respaldo en `php.ini.bak-aiop`).

## Base de datos

```bash
PG=/c/Users/LUIS_FDO/pgsql

# arrancar
"$PG/bin/pg_ctl.exe" -D "$PG/data" -o "-p 5433 -c listen_addresses=127.0.0.1" -l "$PG/server.log" start

# estado
"$PG/bin/pg_isready.exe" -h 127.0.0.1 -p 5433

# detener
"$PG/bin/pg_ctl.exe" -D "$PG/data" stop
```

### Tres roles, tres alcances

Ninguno es superusuario. Las contraseñas quedaron en `C:\Users\LUIS_FDO\pgsql\.*pw`
y están copiadas en `api/.env`.

| Rol | Para qué | Qué puede |
|---|---|---|
| `aiop_migrator` | migraciones y semillas | dueño del esquema; la aplicación no lo usa en runtime (`SEG-38`) |
| `aiop_app` | app de empresa | solo la empresa de la sesión; sin privilegios de esquema |
| `aiop_consola` | consola de plataforma | cuentas y usuarios; **ninguna** política sobre tablas de negocio (`ADM-08`) |

Bases: `aiop` (desarrollo) y `aiop_test` (pruebas).

## Arrancar el proyecto

```bash
# 1. dependencias
composer install --working-dir=api
pnpm install

# 2. esquema y escenario de prueba
php api/artisan migrate:fresh --database=pgsql_migrator --force
php api/artisan db:seed --force

# 3. los tres servidores, cada uno en su terminal
php api/artisan serve --port=8000     # API
pnpm --filter empresa dev             # http://localhost:5173
pnpm --filter consola dev             # http://localhost:5174
```

## Usuarios de prueba

Todos con la contraseña `Clave.Segura.1`.

| Correo | Para probar |
|---|---|
| `pedro@elalamo.test` | propietario con una sola empresa: entra directo |
| `luis@elalamo.test` | dos empresas: aparece el selector |
| `marta@` `gina@` `emily@` `carmina@` `julio@` `sonia@elalamo.test` | un usuario por cada rol |
| `carlos@innovacion.test` | empresa suspendida: entra en solo lectura |
| `sinempresa@aiop.test` | sin membresía: no debe entrar (`ADM-20`) |
| `inactivo@elalamo.test` | desactivado: no debe entrar |
| `laura@aiop.test` | consola, superadmin |
| `soporte@aiop.test` | consola, soporte |

## Pruebas

```bash
# API: dominio, sesión y aislamiento entre empresas
php api/artisan test

# Interfaz: recorre los flujos en un navegador real y deja capturas
node api/tests/navegador/revisar-acceso.mjs       # acceso y cambio de empresa
node api/tests/navegador/revisar-suplidores.mjs   # catálogo de suplidores
node api/tests/navegador/revisar-categorias.mjs   # categorías, etiquetas y borrado
node api/tests/navegador/revisar-productos.mjs    # catálogo, márgenes y ajustes
node api/tests/navegador/revisar-importacion.mjs  # importar y exportar por CSV
node api/tests/navegador/revisar-clientes.mjs     # clientes, exención y crédito
node api/tests/navegador/revisar-facturacion.mjs  # venta rápida de mostrador
node api/tests/navegador/revisar-hoja-factura.mjs # borrador, vista previa, emitir y anular
```

Los dos últimos necesitan las dos aplicaciones andando y no dependen de cuántas
veces se haya sembrado la base: comparan contra lo que había antes, no contra
números fijos.

Las capturas quedan en `api/tests/navegador/capturas/`. Son la evidencia que pide
`CAL-04`: cada componente se cierra con la pantalla funcionando.

## Trampa que ya nos costó un rato

Las pruebas de API comparten proceso y conexión entre peticiones, así que el
contexto de aislamiento de una petición sobrevivía a la siguiente y daba por
bueno código que en el navegador fallaba con 404. `Tests\TestCase::call()` ahora
limpia el contexto antes de cada petición, igual que una conexión nueva en
producción. Si algo pasa en pruebas y falla en el navegador, sospecha de estado
compartido entre peticiones.

## Cosas que conviene saber

- **La importación es por CSV, no por XLSX todavía.** El archivo se escribe con
  punto y coma y BOM, que es lo que Excel en español abre de un doble clic. El
  XLSX nativo entra cuando se agregue la librería de hojas de cálculo.

- **Las migraciones no corren con el usuario de la aplicación.** Si se olvida
  `--database=pgsql_migrator`, fallan con "permiso denegado al esquema public".
  Eso es correcto, no un problema de configuración.
- **El contexto de aislamiento se fija por conexión.** Laravel abre una conexión
  por petición, así que muere con ella. Si algún día se activan conexiones
  persistentes, hay que pasarlo a `SET LOCAL` dentro de una transacción.
- **Las dos apps comparten `localhost`,** así que cada una usa su propio nombre
  de cookie de sesión y de CSRF. En producción irán en dominios distintos.
- **La tipografía se carga de Google Fonts.** Antes de producción hay que
  servirla desde el propio dominio: la política de seguridad de contenido
  (SEG-25) no debería permitir un tercero, y una panadería con internet
  intermitente vería la letra de respaldo.
- **Redis todavía no está.** Colas y caché usan la base de datos mientras tanto;
  cambiar `QUEUE_CONNECTION` y `CACHE_STORE` cuando se instale.
