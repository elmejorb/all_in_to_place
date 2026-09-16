# All in One Place v2

Reconstrucción de la plataforma de inventario, facturación y cuadre de caja, con
una consola de administración separada para las cuentas de empresa.

- **Producto:** SaaS multi-empresa
- **Mercado:** Puerto Rico y Colombia (por confirmar, ver pregunta 1 de
  [docs/12](docs/12-limites-y-pendientes.md)) · moneda por empresa
- **Stack:** API en PHP con Laravel · dos SPA en React · PostgreSQL con RLS
- **Base:** app de empresa y consola administrativa ya en producción
- **Estado:** borrador de requisitos v0.2 (10 de septiembre de 2026)

## Decisión de arquitectura

Sí se puede hacer en el mismo proyecto, pero **no en la misma aplicación**.
Un solo repositorio y una sola API/base de datos, con **dos aplicaciones web
independientes**: la que usan las empresas y la consola con la que se crean y
administran esas cuentas. Cada una con su propio dominio, su propio login y sus
propias rutas. Ver [docs/00-decision-arquitectura.md](docs/00-decision-arquitectura.md).

## Índice

| # | Documento | Contenido |
|---|---|---|
| 00 | [Decisión de arquitectura](docs/00-decision-arquitectura.md) | Dos aplicaciones, una plataforma. Reparto de responsabilidades |
| 01 | [Contexto: sistema actual](docs/01-contexto-sistema-actual.md) | Qué existe hoy y qué se queda corto |
| 02 | [Principios](docs/02-principios.md) | Guías de producto que gobiernan las decisiones |
| 03 | [Arquitectura y multi-empresa](docs/03-arquitectura.md) | Aislamiento, numeración, dinero, bitácora |
| 04 | [Roles y permisos](docs/04-roles-y-permisos.md) | Matriz de permisos de empresa y de plataforma |
| 05 | [App de la empresa](docs/05-app-empresa.md) | Los 11 módulos funcionales |
| 06 | [Consola de plataforma](docs/06-consola-plataforma.md) | Alta de cuentas, planes, soporte, métricas |
| 07 | [Rediseño de interfaz](docs/07-interfaz.md) | Cambios concretos frente a las pantallas actuales |
| 08 | [Requisitos no funcionales](docs/08-no-funcionales.md) | Rendimiento, seguridad, respaldos, pruebas |
| 09 | [Modelo de datos](docs/09-modelo-de-datos.md) | Entidades y reglas transversales |
| 10 | [Stack recomendado](docs/10-stack.md) | Propuesta técnica y alternativas |
| 11 | [Fases y migración](docs/11-fases-y-migracion.md) | Plan de entrega y corte del sistema actual |
| 12 | [Límites y pendientes](docs/12-limites-y-pendientes.md) | Decisiones cerradas, fuera de alcance, pendientes y riesgos |
| 13 | [Seguridad](docs/13-seguridad.md) | Identidad, autorización, datos, web, operación y qué se prueba solo |
| 14 | [Proceso y calidad](docs/14-proceso-y-calidad.md) | Definición de terminado, orden de trabajo, ambiente local |
| 15 | [La hoja de factura](docs/15-pantalla-de-factura.md) | Cómo se escribe una factura: borrador, vista previa y emisión |
| — | [matriz-requisitos.csv](docs/matriz-requisitos.csv) | Todos los requisitos en una tabla para seguimiento |

## Convenciones

- Cada requisito tiene un identificador estable (`PRO-07`, `CAJ-04`, `ADM-03`).
  Una vez asignado no se reutiliza, aunque el requisito se elimine.
- **Prioridad:** `MVP` entra en la primera versión que sustituye al sistema
  actual · `F2` y `F3` son fases posteriores · `Guía` es un principio, no una
  función entregable.
- La matriz CSV se regenera desde los documentos con
  `bash docs/generar-matriz.sh`.
- Versión web de este documento:
  <https://claude.ai/code/artifact/a7d303e5-2eb2-4ea4-9d9b-eb1233a17da0>
