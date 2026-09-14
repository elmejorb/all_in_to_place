# 14 · Proceso y calidad

Cómo se construye y, sobre todo, cuándo algo cuenta como terminado.

## Definición de terminado

Un componente no está hecho cuando compila. Está hecho cuando **se probó
funcionando en la interfaz gráfica**. Esa es la regla del proyecto y aplica a
cada pieza, no al final de la fase.

| ID | Prioridad | Requisito |
|---|---|---|
| CAL-01 | MVP | Ningún componente se da por terminado sin haberse ejecutado en el navegador, con datos reales de semilla, y verificado a mano el flujo completo que habilita. |
| CAL-02 | MVP | Cada endpoint se entrega junto con la pantalla que lo consume. No se acumulan endpoints sin interfaz ni pantallas contra datos simulados. |
| CAL-03 | MVP | Antes de dar por terminado un componente se prueba también lo que debe fallar: sin sesión, con rol insuficiente, con datos inválidos y con datos de otra empresa. |
| CAL-04 | MVP | Toda entrega deja evidencia: captura de la pantalla funcionando y salida de las pruebas ejecutadas. |
| CAL-05 | MVP | Las dependencias necesarias para probar (PHP, Composer, Node, PostgreSQL, Redis) se instalan y se documentan en el arranque del ambiente, no se dejan como pendiente. |
| CAL-06 | MVP | El proyecto arranca completo con un solo comando desde un clon limpio, incluyendo migraciones y semillas. Si no arranca, eso es el primer defecto a corregir. |
| CAL-07 | MVP | Las semillas crean un escenario usable: dos empresas con datos distintos, un usuario por rol, productos con y sin existencia, facturas en cada estado y un turno de caja abierto. |
| CAL-08 | MVP | Cada pantalla se revisa en escritorio y en 390 px de ancho antes de darla por terminada (`RNF-03`). |
| CAL-09 | MVP | Cada pantalla se revisa en tema claro y oscuro (`IU-03`) y con recorrido por teclado (`RNF-04`). |
| CAL-10 | MVP | Un defecto encontrado al probar se corrige antes de pasar al componente siguiente, o se registra explícitamente con su motivo. |
| CAL-11 | MVP | Las reglas de cálculo se prueban con casos numéricos escritos antes de la implementación: impuestos, descuentos, totales, existencias y diferencia de caja. |
| CAL-12 | F2 | Prueba automatizada de extremo a extremo de los tres flujos críticos —facturar, importar productos y cerrar turno— ejecutada en cada integración. |

## Orden de trabajo de cada componente

Siempre el mismo, para que probar no quede al final:

1. **Casos numéricos primero** cuando hay cálculo (`CAL-11`). Se escriben los
   ejemplos con su resultado esperado antes del código.
2. **Dominio.** La regla en `app/Domain`, con sus pruebas pasando sin base de
   datos.
3. **Datos.** Migración, modelo, política RLS y semilla del escenario.
4. **Endpoint.** Validación, política de autorización, recurso de respuesta.
5. **Pruebas del endpoint**, incluyendo los casos que deben fallar (`CAL-03`).
6. **Pantalla.** Componente en React contra la API real, no contra datos
   simulados.
7. **Prueba a mano en el navegador** (`CAL-01`): el flujo completo, en escritorio
   y en móvil, en los dos temas, con teclado.
8. **Evidencia y cierre** (`CAL-04`).

## Ambiente local

Lo que hay que tener instalado y funcionando desde el primer día:

| Pieza | Para qué |
|---|---|
| PHP 8.3 o superior con Composer | La API |
| Node con pnpm | Las dos SPA y el paquete `ui` |
| PostgreSQL 16 o superior | Base de datos con RLS |
| Redis | Colas y caché |
| Docker y Docker Compose | Levantar base de datos, Redis y almacenamiento sin instalarlos a mano |
| Navegador con las herramientas de desarrollo | Probar la interfaz (`CAL-01`) |
| Playwright | Pruebas de extremo a extremo y capturas automáticas |

Comandos que deben existir y funcionar en el repositorio:

```
composer install && pnpm install     # dependencias
docker compose up -d                 # base de datos, redis, almacenamiento
php artisan migrate --seed           # esquema y escenario de prueba
php artisan serve                    # API en /v1 y /v1/admin
pnpm --filter empresa dev            # SPA de la empresa
pnpm --filter consola dev            # SPA de plataforma
composer test && pnpm test           # pruebas de API y de interfaz
```

## Convenciones de código

- **PHP:** formato automático con Pint y análisis estático estricto, con el nivel
  más alto exigido en `app/Domain`.
- **TypeScript:** modo estricto, sin `any` en el código propio.
- **Nombres del dominio en español** (`producto`, `documento`, `turno_caja`),
  igual que en `09-modelo-de-datos.md`, para que el código hable el idioma del
  negocio. Las palabras del framework quedan en inglés.
- **Sin lógica de negocio en los controladores** ni en los componentes de React:
  los controladores validan y delegan; los componentes muestran y piden.
- **Un solo lugar por regla.** Si el cálculo del impuesto aparece dos veces, una
  de las dos está por quedar desactualizada.
