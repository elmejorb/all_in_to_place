# 11 · Fases y migración

Cada componente de cada fase se cierra con la definición de terminado de
[14-proceso-y-calidad.md](14-proceso-y-calidad.md): probado en el navegador, en
móvil, en los dos temas, y con los casos que deben fallar.

## Fase 0 · Cimientos — 2 semanas

API en Laravel con las dos superficies, monorepo con las dos SPA de React,
sistema de diseño con tokens, autenticación separada, empresas y membresías,
identificadores ULID, aislamiento con RLS y sus pruebas, integración continua y
despliegue.

**Criterio de salida:** se puede crear una empresa desde la consola, recibir la
invitación por correo y entrar a la app vacía, con las pruebas de aislamiento
pasando en la integración continua y el proyecto arrancando de un clon limpio
con un solo comando (`CAL-06`).

### Qué pasa con la consola actual

La consola AIOP ya existe, así que hay que decidir explícitamente entre dos
caminos. La recomendación es el segundo:

| Camino | A favor | En contra |
|---|---|---|
| Mantenerla como está y solo agregarle lo que falta | Nada que reescribir | Arrastra los datos de demostración, la mezcla de idiomas y los IDs visibles; y quedaría fuera del monorepo, del paquete `ui` y de la API nueva |
| Reconstruirla en React sobre la API nueva, conservando su identidad visual | Cuatro pantallas, poca lógica; entra al monorepo, a las pruebas y al sistema de diseño desde el principio | Dos o tres semanas de la Fase 0 y 1 |

Se conserva el tema visual morado y la estructura del menú: lo que cambia es la
mecánica, no la apariencia (`IU-02`).

## Fase 1 · MVP operable — 6 a 8 semanas

Todo lo marcado `MVP`: suplidores, categorías, productos con precio, inventario
por movimientos, importación con vista previa, clientes, facturación con pagos e
impuesto desglosado, venta rápida, Hoja de Cuadre, panel y reportes base, y
consola con alta y suspensión de cuentas.

**Criterio de salida:** una empresa real opera un día completo en v2 —vender,
cobrar, recibir mercancía y cuadrar la caja— sin volver al sistema actual.

## Fase 2 · Profundidad — 4 a 6 semanas

Notas de crédito y devoluciones, cobranza, sucursales y traspasos, conteo
físico, lotes y vencimientos, depósitos, histórico de cuadres, notificaciones y
preferencias, segundo factor, impersonación con bitácora, planes y límites,
suscripción y cobros.

**Criterio de salida:** todas las empresas migradas, soporte puede atender sin
pedir credenciales al cliente, y la suscripción se administra desde la consola.

## Fase 3 · Crecimiento — por definir

Variantes y combos, listas de precio, órdenes de compra sugeridas, facturas
recurrentes, terminal de tarjeta, envío por WhatsApp, reportes programados,
restauración puntual y roles personalizados. Se prioriza con el uso real de las
fases anteriores.

## Migración desde el sistema actual

| ID | Prioridad | Requisito |
|---|---|---|
| MIG-01 | MVP | Importar empresas, usuarios, suplidores, categorías, productos y existencias actuales, conservando las tasas de impuesto tal como están hoy (7% y 11.5%) hasta que se revisen. |
| MIG-02 | MVP | Cada producto migrado entra con un movimiento de inventario inicial de tipo "saldo de apertura", para que el kardex tenga origen. |
| MIG-03 | MVP | Revisión de datos cruzados antes de migrar: separar nombre de empresa y contacto en suplidores, y resolver empresas con nombre duplicado. |
| MIG-04 | MVP | Corte por fecha: las facturas nuevas se emiten solo en v2; el sistema actual queda en modo lectura durante el periodo de transición acordado. |
| MIG-05 | MVP | Ensayo de migración completo en un ambiente de prueba, con reporte de diferencias revisado y aprobado antes del corte real. |
| MIG-06 | F2 | Importar el histórico de facturas de los últimos meses que se acuerden, solo como consulta, sin afectar inventario ni cuadres. |
| MIG-07 | F2 | Guía de cambios para los usuarios: qué se movió de sitio y cómo se hace ahora cada tarea del día. |
| MIG-08 | MVP | Limpieza de los datos de la consola actual antes de migrar: empresas duplicadas (12 y 13 "Evolución Digital", 14 y 15 "Innovacion Digital", con la misma dirección y teléfono), la empresa sin usuarios y los usuarios sin empresa. |
| MIG-09 | MVP | Reasignar el rol real de cada usuario: hoy los nueve son "Administrador" y los otros cuatro roles no se usan. |
| MIG-10 | MVP | Conservar los métodos de pago actuales (Cheque, ATH Móvil, PayPal) y completar el catálogo con efectivo, tarjeta y transferencia (`ADM-17`). |
| MIG-11 | MVP | Asignar país y moneda a cada empresa migrada según su dirección real, no por omisión (`ADM-22`). |
| MIG-12 | MVP | Al migrar, cada registro recibe su identificador público ULID; los identificadores numéricos actuales dejan de ser visibles y no se publican en la API (`ARQ-13`). |

## Orden de migración por empresa

1. Congelar altas en el sistema actual y exportar los catálogos.
2. Revisar y corregir los datos cruzados (`MIG-03`) con el dueño de la empresa.
3. Ensayo en ambiente de prueba y revisión del reporte de diferencias.
4. Sesión de trabajo con el equipo de la empresa: media hora sobre las pantallas
   nuevas, con sus propios datos ya cargados.
5. Corte: importación final, conteo físico de apertura si aplica, y primera
   factura en v2 el mismo día.
6. Sistema actual en solo lectura durante el periodo acordado.

Conviene empezar por la empresa con el catálogo más limpio y el volumen más bajo,
no por la más grande.
