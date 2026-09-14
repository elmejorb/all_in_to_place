# 12 · Límites y pendientes

## Decisiones ya cerradas

| Decisión | Resultado | Dónde vive |
|---|---|---|
| Separación de la administración | Dos aplicaciones, un solo repositorio y una sola API. Ya existe en la práctica | `00`, `06` |
| Lenguaje de la API | PHP con Laravel, no Lumen | `10` |
| Frontend | React con TypeScript, dos SPA que consumen la API | `10` |
| Identificadores públicos | ULID de 26 caracteres. Los numéricos quedan como índice interno y no salen por la API | `ARQ-13`, `ARQ-19`, `ARQ-21` |
| Seguridad | Se aplica el conjunto completo del documento 13, con pruebas que bloquean el despliegue | `13` |
| Cuándo algo está terminado | Probado a mano en la interfaz gráfica, en móvil y en los dos temas | `14` |

## Fuera de alcance

- **Contabilidad completa:** libro mayor, conciliación bancaria y estados
  financieros. Se resuelve exportando a la herramienta contable.
- **Nómina y control de asistencia.**
- **Tienda en línea** y catálogo público con carrito.
- **Aplicación móvil nativa:** la web responsiva cubre el uso móvil en estas
  fases. La API queda lista para cuando se decida.
- **Integración con lector de tarjetas físico:** Fase 3 (`FAC-18`).

## Decisiones pendientes

Cada una cambia el alcance de la Fase 1, así que conviene cerrarlas antes de
empezar a construir. Están en orden de impacto.

| # | Pregunta | Qué depende de la respuesta |
|---|---|---|
| 1 | **¿Uno o dos países?** Los datos actuales mezclan Colombia (Planeta Rica, Córdoba) y Puerto Rico (Bayamón). Si Colombia entra en serio, ¿hace falta facturación electrónica ante la DIAN? | Es la pregunta más grande del proyecto. La DIAN es un módulo completo: resolución de numeración, XML firmado, envío, acuse y contingencia. Cambia el tamaño de la Fase 1 y del equipo |
| 2 | **Hoja de Cuadre.** Los requisitos `CAJ-01` a `CAJ-10` asumen cierre de caja por turno con conteo de efectivo y diferencia. ¿Es eso lo que se cuadra hoy, o incluye algo más, como comisiones por vendedor? | Todo el módulo 5.9 |
| 3 | **Tasas de impuesto.** ¿De dónde salen el 7% y el 11.5% de los productos actuales, y cuál queda por defecto en cada país? | `EMP-02`, `FAC-04`, `RPT-07`, `MIG-01` |
| 4 | **Venta de mostrador.** ¿Toda venta se documenta como factura, o hace falta un punto de venta con caja, escáner y recibo simple como flujo principal? | `FAC-12`, prioridad de `CAJ-01` |
| 5 | **La consola actual:** ¿se reconstruye en React sobre la API nueva o se mantiene y se le agrega lo que falta? La recomendación está en `11-fases-y-migracion.md`. | Dos o tres semanas de la Fase 0 |
| 6 | **Sucursales.** ¿Alguna empresa tiene ya más de un local o almacén? | Si sí, `EMP-08` sube a Fase 1 y cambia el modelo de existencias |
| 7 | **PayPal.** ¿Es un método de cobro que se registra a mano, o hay que integrar la pasarela y conciliar? | `ADM-17`, `FAC-07`, posible módulo nuevo |
| 8 | **Cobro de la suscripción.** ¿Se cobra dentro de la plataforma o por fuera? | Si es dentro, `ADM-12` sube a Fase 1 |
| 9 | **Escala esperada.** ¿Cuántas empresas, usuarios simultáneos y facturas mensuales se proyectan a 12 meses? | `RNF-01`, `RNF-02`, dimensionamiento del servidor |
| 10 | **Base de datos.** ¿PostgreSQL es viable en el hosting actual? Sin él no hay RLS y el aislamiento queda solo en el código. | `ARQ-04`, `RNF-07`, y la nota sobre MySQL de `10-stack.md` |
| 11 | **Plantilla comprada.** ¿Tiene versión React y licencia para usarla en las dos apps? | Punto de partida visual del paquete `ui` |
| 12 | **Idioma por defecto** de una empresa nueva: español o inglés. | `EMP-03`, catálogos de traducción |

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Colombia aparece a mitad del camino y obliga a rehacer facturación | Cerrar la pregunta 1 por escrito antes de la Fase 1; hasta entonces, país y moneda quedan configurables pero sin prometer DIAN |
| La migración de existencias no cuadra con el conteo físico real | Conteo de apertura en el corte (`MIG-02`, paso 5 de la migración) |
| El equipo de la empresa resiste el cambio de pantallas | Sesión de media hora con sus propios datos ya cargados, antes del corte |
| Las reglas de impuesto quedan mal y se factura incorrecto | Reglas en `app/Domain` con casos numéricos escritos antes del código (`CAL-11`) y revisión con el contador |
| Un error de permisos expone datos entre empresas | RLS en base de datos, ULID en lugar de IDs recorribles, y la suite de aislamiento que bloquea el despliegue (`ARQ-04`, `RNF-07`, `SEG-12`) |
| El alcance de la Fase 1 crece y se atrasa el reemplazo | Las doce preguntas de arriba cerradas por escrito antes de empezar |
| Se acumulan endpoints sin interfaz y el avance real no se ve | Definición de terminado del documento 14: cada componente probado en pantalla antes del siguiente |
