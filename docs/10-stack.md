# 10 · Stack

La API se hace en **PHP**. Esa es una decisión tomada; lo que sigue es cómo
aprovecharla mejor.

## Framework de la API: Laravel, no Lumen

**Recomendación: Laravel** (versión estable vigente, PHP 8.3 o superior), usado
como API sin vistas.

Lumen dejó de ser la recomendación oficial para proyectos nuevos y no recibe
desarrollo activo; el propio equipo de Laravel sugiere empezar en Laravel. Y el
argumento histórico de Lumen —ser más liviano y rápido— ya no compensa: este
sistema necesita justo lo que Lumen recorta.

| Lo que necesita este proyecto | Laravel | Lumen |
|---|---|---|
| Colas para importaciones, PDF y correos (`ARQ-16`) | Incluido, con Horizon | Limitado, sin panel |
| Tareas programadas (recordatorios de cobro, cierres) | Incluido | Hay que armarlo |
| Políticas de autorización por rol (`ROL-03`) | Policies y Gates | Manual |
| Dos ámbitos de autenticación separados (`ARQ-03`) | Guards y providers múltiples | Manual |
| Migraciones y semillas versionadas (`RNF-13`) | Incluido | Incluido |
| Importación y exportación de Excel (`PRO-07`, `PRO-09`) | Ecosistema maduro | Compatible pero sin integración |
| Transacciones con bloqueo de fila para numeración (`ARQ-08`) | Incluido | Incluido |

### Alternativas en PHP que se evaluaron

| Opción | Por qué no |
|---|---|
| Symfony | Igual de capaz, más ceremonia y curva más larga. Solo conviene si el equipo ya lo domina |
| Slim o Mezzio | Micro-framework: habría que armar a mano colas, auth, migraciones y validación |
| API Platform | Muy rápido para CRUD, pero este dominio tiene reglas propias (impuestos, cuadre, numeración) que terminan peleando con la generación automática |
| Lumen | Sin desarrollo activo y sin las piezas que este proyecto necesita |

## Piezas del stack

| Capa | Elección | Nota |
|---|---|---|
| API | Laravel, solo rutas `/v1` y `/v1/admin` | Sin Blade en la API; la interfaz va aparte |
| Autenticación | Sanctum con sesión por cookie, dos guards y dos providers | `empresa` y `plataforma` no comparten sesión (`ARQ-03`) |
| Base de datos | PostgreSQL 16 o superior con Row Level Security | Ver la nota sobre MySQL más abajo |
| Reglas de negocio | Carpeta `app/Domain`, clases puras sin dependencias del framework | Es lo que se prueba primero (`RNF-08`) |
| Permisos | Policies de Laravel sobre roles en base de datos | La matriz de `04-roles-y-permisos.md` |
| Colas y programador | Redis con Horizon y el scheduler de Laravel | Importaciones, PDF, correos, recordatorios |
| Bitácora | Tabla propia append-only escrita por observers | `ARQ-11`. No se usa una librería que permita borrar |
| Excel y CSV | Librería de hojas de cálculo en trabajo en cola | Importación en tres pasos (`PRO-07`) |
| PDF | Plantilla HTML renderizada en servidor y guardada al emitir | `RNF-12`, `FAC-10` |
| Archivos | Almacenamiento compatible con S3 y URLs firmadas | Logos, comprobantes, PDF |
| Correo | Proveedor transaccional con dominio verificado | `NOT-02` |
| Interfaz | React con TypeScript y Vite, una SPA por aplicación | Decisión tomada: el equipo trabaja en React |
| Pruebas | Pest o PHPUnit, más análisis estático estricto en `app/Domain` | `RNF-07`, `RNF-08` |
| Operación | Docker, integración continua con migraciones automáticas, Sentry | `RNF-11`, `RNF-13` |

## Estructura del repositorio

```
all-in-one-place/
├─ api/                      Laravel
│  ├─ app/
│  │  ├─ Domain/             impuestos, totales, existencias, numeracion, cuadre
│  │  ├─ Http/V1/            endpoints de la app de empresa
│  │  └─ Http/V1Admin/       endpoints de la consola
│  ├─ database/migrations/   esquema y politicas RLS
│  └─ tests/
│     ├─ Domain/             reglas de calculo
│     └─ Aislamiento/        un caso por endpoint (RNF-07)
├─ apps/
│  ├─ empresa/               SPA de la empresa
│  └─ consola/               SPA de plataforma (la actual, migrada)
├─ packages/ui/              tokens y componentes compartidos
└─ docs/                     estos requisitos
```

`app/Domain` no conoce Eloquent ni HTTP: recibe datos y devuelve resultados. Ahí
viven las reglas que no pueden estar mal (`FAC-05`, `INV-02`, `CAJ-04`) y se
prueban sin base de datos, en milisegundos.

## Aislamiento por empresa en Laravel

Dos capas, y las dos hacen falta:

1. **En la base de datos.** Un middleware fija la empresa de la sesión con
   `SET LOCAL app.empresa_id` al abrir la transacción de la petición, y las
   políticas RLS de PostgreSQL filtran por esa variable. Si un endpoint olvida el
   filtro, la base igual no devuelve filas de otra empresa.
2. **En el código.** Un trait `PerteneceAEmpresa` con global scope, aplicado a
   todo modelo de negocio, más la suite de aislamiento de `RNF-07` que intenta
   leer y escribir datos de otra empresa por cada endpoint y falla el despliegue
   si alguno lo permite.

### Si se exige MySQL

MySQL no tiene Row Level Security. En ese caso el aislamiento queda solo en la
capa 2, lo que significa que un `DB::table()` mal escrito o un `withoutGlobalScope`
olvidado sí puede cruzar datos. Si esa es la vía, entonces:

- La suite de aislamiento (`RNF-07`) pasa de recomendable a bloqueante.
- Se prohíbe el uso de `DB::table()` y consultas crudas fuera de una capa de
  repositorio revisada.
- Todo modelo de negocio hereda de una clase base que aplica el scope, y una
  prueba verifica que ningún modelo se saltó la herencia.

Con PostgreSQL esta discusión no existe. Es el motivo principal para preferirlo.

## Frontend: React

Decidido: **React con TypeScript, dos SPA que consumen la API de Laravel**, una
por aplicación, compartiendo el paquete `ui`. Laravel queda como API pura, sin
Blade, sin Inertia y sin Livewire.

| Pieza | Elección | Por qué |
|---|---|---|
| Compilador | Vite | Arranque y recarga inmediatos, y es lo que Laravel ya integra |
| Rutas | React Router | Filtros y paginación en la URL (`PRO-06`) |
| Datos del servidor | TanStack Query | Caché por consulta, reintentos y estados de carga sin escribirlos a mano |
| Formularios | React Hook Form con validación por esquema | El mismo esquema valida en el cliente y describe lo que exige la API |
| Tablas | TanStack Table | Orden, selección y columnas fijas sobre datos paginados en servidor |
| Estilos | Tailwind con los tokens del paquete `ui` | Un solo sistema visual para las dos apps (`IU-01`) |
| Componentes | Primitivas accesibles headless | Foco, teclado y lector de pantalla resueltos de base (`RNF-04`) |
| Pruebas | Vitest con Testing Library, y Playwright para los flujos completos | `RNF-08` |

Las dos aplicaciones actuales usan una plantilla comprada (la consola en morado,
la app de empresa en verde), y eso ya cumple `IU-02`: se distinguen a simple
vista. Se conserva ese punto de partida visual y se cambia la mecánica, no el
color. Si la plantilla comprada tiene versión React, se toma de ahí el tema y se
reconstruyen los componentes contra el paquete `ui`; no se arrastra su
JavaScript.

## Lo que conviene no negociar

Independientemente de las piezas:

1. El aislamiento por empresa se verifica con pruebas que bloquean el despliegue.
2. El dinero se guarda en centavos como entero, nunca en `float`.
3. Las reglas de cálculo viven en `app/Domain`, sin framework y con pruebas.
4. Las migraciones son versionadas y se aplican en el despliegue.
5. Las dos aplicaciones no comparten sesión ni cookie.
