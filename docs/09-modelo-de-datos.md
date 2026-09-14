# 09 · Modelo de datos

Entidades principales. Todas las de negocio llevan `empresa_id`, `creado_en`,
`creado_por` y borrado lógico; los importes se guardan en centavos.

## Entidades

| Entidad | Contenido |
|---|---|
| `empresa` | Datos fiscales, país, moneda, preferencias, estado de la cuenta |
| `sucursal` | Almacén y punto de venta de una empresa |
| `usuario` | Identidad global, sin empresa asociada. Nombres y apellidos separados |
| `membresia` | Usuario más empresa más rol. Es lo que da acceso |
| `suplidor` | Razón social, contacto, términos de pago |
| `categoria` | Nombre, color, orden de presentación |
| `producto` | Catálogo, costo, precio, mínimos, marca de servicio |
| `movimiento_inventario` | Asiento de existencia con tipo, cantidad y referencia |
| `compra` | Recepción de mercancía del suplidor con sus renglones |
| `cliente` | Ficha, exención de impuesto, límite de crédito |
| `documento` | Cotización, factura o nota de crédito |
| `documento_renglon` | Producto, cantidad, precio, descuento, impuesto |
| `pago` | Método, monto, referencia, documento al que aplica |
| `serie_documento` | Prefijo y secuencia por empresa y sucursal |
| `impuesto` | Tasa con desglose estatal y municipal |
| `metodo_pago` | Catálogo global de la plataforma: efectivo, cheque, ATH Móvil, tarjeta, transferencia, PayPal |
| `empresa_metodo_pago` | Qué métodos del catálogo habilita cada empresa (`ADM-17`) |
| `turno_caja` | Apertura, cierre, fondo, contado, diferencia |
| `movimiento_caja` | Gasto, retiro, entrada o depósito del turno |
| `importacion` | Archivo, filas procesadas, resultado, autor |
| `notificacion` | Evento, canal, estado de lectura |
| `bitacora` | Append-only, con valor anterior y nuevo |
| `plan` | Límites y módulos incluidos |
| `suscripcion` | Ciclo, precio, estado de pago |
| `usuario_plataforma` | Identidad del ámbito de la consola |
| `impersonacion` | Motivo, vigencia, actor y empresa afectada |

## Reglas transversales

- La **existencia** de un producto es la suma de sus movimientos.
- El **total** de un documento es la suma de sus renglones más impuestos.
- El **saldo** de un cliente es la suma de sus documentos menos sus pagos.

Ninguno de los tres se guarda como dato editable a mano. Sí pueden almacenarse
en caché para consulta rápida, siempre recalculables desde su origen.

## Qué tabla lleva empresa_id

| Grupo | Tablas | `empresa_id` |
|---|---|---|
| Negocio | suplidor, categoria, producto, movimiento_inventario, compra, cliente, documento, documento_renglon, pago, serie_documento, impuesto, turno_caja, movimiento_caja, importacion, notificacion, sucursal | Sí, con política RLS |
| Identidad | usuario | No. La identidad es global |
| Enlace | membresia | Sí. Es la tabla que define el acceso |
| Catálogos globales | metodo_pago, plan | No. Los administra la plataforma |
| Enlace de catálogo | empresa_metodo_pago | Sí |
| Plataforma | suscripcion, usuario_plataforma, impersonacion | Referencia a empresa, sin RLS de empresa |
| Auditoría | bitacora | Sí, y además el actor de plataforma cuando aplica |

## Decisiones de tipos

- Importes: `bigint` en centavos.
- Porcentajes de impuesto y descuento: `integer` en milésimas
  (11.5% se guarda como `11500`).
- Cantidades de inventario: `numeric(14,3)`, para permitir libras y unidades
  fraccionadas.
- Identificadores: dos por tabla. `id` `bigint` autoincremental para las llaves
  foráneas y los índices, que no sale nunca de la base; y `ulid` de 26
  caracteres como identificador público, el único que aparece en URLs,
  respuestas de API y exportaciones (`ARQ-13`).
- País y moneda por empresa, con la moneda fija una vez emitido el primer
  documento.
- Fechas: `timestamptz` siempre. El día de negocio se calcula con la zona horaria
  de la empresa (`ARQ-10`).
