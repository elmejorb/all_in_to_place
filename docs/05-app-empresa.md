# 05 · App de la empresa

Los once módulos funcionales de la aplicación que usan las empresas.

- [5.1 Acceso y cuentas de usuario](#51-acceso-y-cuentas-de-usuario)
- [5.2 Empresa y sucursales](#52-empresa-y-sucursales)
- [5.3 Suplidores](#53-suplidores)
- [5.4 Categorías](#54-categorías)
- [5.5 Productos y servicios](#55-productos-y-servicios)
- [5.6 Inventario y movimientos](#56-inventario-y-movimientos)
- [5.7 Clientes](#57-clientes)
- [5.8 Facturación](#58-facturación)
- [5.9 Hoja de Cuadre](#59-hoja-de-cuadre-cierre-de-caja)
- [5.10 Panel y reportes](#510-panel-y-reportes)
- [5.11 Notificaciones](#511-notificaciones)

## 5.1 Acceso y cuentas de usuario

| ID | Prioridad | Requisito |
|---|---|---|
| AUT-01 | MVP | Inicio de sesión con correo y contraseña, con bloqueo temporal progresivo tras 5 intentos fallidos. |
| AUT-02 | MVP | No hay registro público. Las cuentas de empresa nacen en la consola; los demás usuarios entran por invitación. |
| AUT-03 | MVP | Invitación por correo con rol asignado, enlace de un solo uso que caduca en 72 horas y se puede reenviar o revocar. |
| AUT-04 | MVP | Recuperación de contraseña por enlace de un solo uso; el mensaje no revela si el correo existe. |
| AUT-05 | MVP | Un usuario pertenece a varias empresas y alterna entre ellas sin cerrar sesión ni volver a escribir su clave. |
| AUT-06 | MVP | Cierre de sesión por inactividad configurable por empresa: 12 horas por defecto, 30 minutos en perfiles de caja. |
| AUT-07 | F2 | Segundo factor por aplicación de códigos (TOTP), opcional para todos y exigible por el Propietario a toda la empresa. |
| AUT-08 | F2 | Pantalla de sesiones activas con dispositivo, lugar aproximado y último acceso, y opción de cerrar cualquiera. |
| AUT-09 | F2 | Código PIN corto para cambiar de cajero en el mismo dispositivo sin cerrar la sesión de la empresa. |
| AUT-10 | F3 | Inicio de sesión con Google para empresas que lo prefieran. |

## 5.2 Empresa y sucursales

| ID | Prioridad | Requisito |
|---|---|---|
| EMP-01 | MVP | Datos de la empresa: nombre legal, nombre comercial, número de registro de comerciante, teléfono, correo, dirección física y postal, logo para documentos. |
| EMP-02 | MVP | Configuración fiscal: tasas de impuesto disponibles con su desglose (estatal y municipal), tasa por defecto, y manejo de productos y clientes exentos. |
| EMP-03 | MVP | Preferencias: idioma, formato de fecha, zona horaria, decimales y redondeo. |
| EMP-04 | MVP | Series y plantillas de documentos: prefijo, próximo número, términos de pago por defecto, notas al pie y mensaje de agradecimiento. |
| EMP-05 | MVP | Selector de empresa en la barra superior con búsqueda a partir de 5 empresas, iniciales, empresa actual marcada y el rol del usuario en cada una. Sin identificadores internos. |
| EMP-06 | MVP | El usuario solo ve las empresas donde tiene membresía activa; una empresa suspendida se muestra en gris con su estado. |
| EMP-07 | MVP | Aviso al crear una empresa con nombre igual a otra existente, obligando a confirmar o diferenciar. |
| EMP-08 | F2 | Sucursales y almacenes: existencia por sucursal, traspasos con documento, y series de facturación por sucursal. |
| EMP-09 | F2 | Horarios y datos de contacto por sucursal, usados en los documentos que emite. |

## 5.3 Suplidores

| ID | Prioridad | Requisito |
|---|---|---|
| SUP-01 | MVP | Campos separados y bien nombrados: razón social, nombre comercial, número de cliente que el suplidor nos asignó, teléfono, correo, vendedor de contacto, términos de pago, notas. Corrige el cruce actual entre nombre de empresa y nombre de contacto. |
| SUP-02 | MVP | Validaciones: razón social obligatoria, número de cliente único por empresa, teléfono con máscara y correo con formato. |
| SUP-03 | MVP | Listado con búsqueda, orden por columna, paginación en servidor y exportación de la vista filtrada. |
| SUP-04 | MVP | Desactivar en vez de borrar: un suplidor inactivo no aparece en selectores pero conserva su historial. |
| SUP-05 | F2 | Ficha del suplidor con sus productos, últimas recepciones de compra, total comprado por periodo y plazo promedio de entrega. |
| SUP-06 | F2 | Varios contactos por suplidor, cada uno con cargo y teléfono. |
| SUP-07 | F3 | Cuentas por pagar: saldo con el suplidor y registro de pagos. |

## 5.4 Categorías

| ID | Prioridad | Requisito |
|---|---|---|
| CAT-01 | MVP | Nombre único por empresa, descripción opcional y color de etiqueta elegido de una paleta accesible. |
| CAT-02 | MVP | El listado muestra cuántos productos tiene cada categoría y su valor de inventario. |
| CAT-03 | MVP | No se puede eliminar una categoría con productos: el sistema ofrece reasignarlos a otra en la misma acción. |
| CAT-04 | F2 | Reordenar categorías para controlar el orden en que aparecen en la pantalla de venta. |
| CAT-05 | F3 | Subcategorías de un nivel. |

## 5.5 Productos y servicios

| ID | Prioridad | Requisito |
|---|---|---|
| PRO-01 | MVP | Campos: nombre, código interno (SKU), código de barras, descripción, categoría, suplidor, unidad de medida, costo, precio de venta, tasa de impuesto, existencia, existencia mínima, marca de servicio, activo. |
| PRO-02 | MVP | Precio de venta y margen calculado visible al capturar, con aviso cuando el precio queda por debajo del costo. |
| PRO-03 | MVP | Al marcar "servicio", los campos de existencia se ocultan y dejan de exigirse; el producto no genera movimientos de inventario. |
| PRO-04 | MVP | Nombres de campo completos en la interfaz: "Existencia mínima" e "Impuesto (%)", no "Exit. Min" ni "Tax". |
| PRO-05 | MVP | Búsqueda por nombre, SKU o código de barras, con soporte de lector de códigos en el campo de búsqueda. |
| PRO-06 | MVP | Filtros combinables y recordados en la URL: categoría, suplidor, estado de existencia (agotado, bajo mínimo, normal), activo, servicio. |
| PRO-07 | MVP | Importación por plantilla en tres pasos: descargar plantilla, subir y ver una vista previa con validación fila por fila, confirmar. Informe de errores descargable y opción de actualizar los existentes por SKU. |
| PRO-08 | MVP | Una importación es atómica: o entra completa o no entra nada, y queda registrada con quién la hizo y cuántas filas afectó. |
| PRO-09 | MVP | Exportación de la vista filtrada a CSV y XLSX, con los filtros aplicados anotados en el archivo. |
| PRO-10 | MVP | Acciones en lote sobre la selección: cambiar categoría, suplidor o tasa, activar o desactivar, y ajustar precio por porcentaje con vista previa del resultado. |
| PRO-11 | F2 | Imagen principal del producto, usada en la pantalla de venta y en el catálogo. |
| PRO-12 | F2 | Historial de cambios de costo y precio con fecha y usuario. |
| PRO-13 | F3 | Combos o paquetes que descuentan varios productos al venderse. |
| PRO-14 | F3 | Variantes por talla, color o sabor con existencia propia. |
| PRO-15 | F3 | Listas de precio por tipo de cliente y precio de mayoreo por cantidad. |

## 5.6 Inventario y movimientos

| ID | Prioridad | Requisito |
|---|---|---|
| INV-01 | MVP | Toda variación de existencia se guarda como movimiento con tipo (entrada, venta, devolución, ajuste, traspaso, merma), cantidad, costo unitario, usuario, fecha y referencia al documento que la originó. |
| INV-02 | MVP | La existencia mostrada se deriva de los movimientos; no es un número que se pueda teclear directamente. |
| INV-03 | MVP | Un ajuste manual exige motivo de una lista y comentario, y aparece en la bitácora y en el reporte de ajustes. |
| INV-04 | MVP | Recepción de compra: se captura la factura del suplidor con sus renglones, se actualiza existencia y se recalcula el costo por promedio ponderado. |
| INV-05 | MVP | Panel de existencias con los productos agotados y bajo mínimo, ordenados por impacto en ventas, y acción directa para reponer. |
| INV-06 | MVP | Valorización del inventario a costo, con corte a una fecha y desglose por categoría. |
| INV-07 | MVP | Kardex por producto: todos sus movimientos con saldo corrido y enlace al documento. |
| INV-08 | F2 | Conteo físico: generar hoja de conteo, capturar lo contado, ver diferencias y aplicar el ajuste en un solo paso registrado. |
| INV-09 | F2 | Fecha de vencimiento y lote para productos perecederos, con alerta por vencer. |
| INV-10 | F3 | Sugerencia de orden de compra por suplidor a partir de mínimos, ventas recientes y plazo de entrega. |

## 5.7 Clientes

| ID | Prioridad | Requisito |
|---|---|---|
| CLI-01 | MVP | Ficha: nombre, tipo (persona o empresa), teléfono, correo, dirección, exención de impuesto con número de certificado, términos de pago, límite de crédito y notas. |
| CLI-02 | MVP | Creación al vuelo desde la pantalla de facturación, con solo el nombre y el teléfono como mínimo. |
| CLI-03 | MVP | Estado de cuenta: facturas, pagos, saldo pendiente y antigüedad de saldos (corriente, 30, 60, 90 o más días). Entra con Facturación: sin documentos no hay cuenta que mostrar. |
| CLI-04 | F2 | Aviso de posible duplicado al capturar un cliente con teléfono o nombre parecido a otro existente. |
| CLI-05 | F3 | Fusionar dos clientes conservando su historial. |

## 5.8 Facturación

| ID | Prioridad | Requisito |
|---|---|---|
| FAC-01 | MVP | Flujo cotización a factura a pago, con el enlace entre documentos siempre visible. |
| FAC-02 | MVP | Estados explícitos: borrador, emitida, pagada parcial, pagada, vencida, anulada. El estado se calcula, no se elige a mano. |
| FAC-03 | MVP | Renglones con producto del catálogo o texto libre, cantidad, precio, descuento por renglón en monto o porcentaje, e impuesto por renglón. |
| FAC-04 | MVP | Totales desglosados: subtotal, descuento, impuesto separado en estatal y municipal, total, pagado y saldo. |
| FAC-05 | MVP | Reglas de cálculo fijas y probadas: impuesto sobre la base neta después de descuento, redondeo a dos decimales por renglón, y el total nunca difiere de la suma de sus partes. |
| FAC-06 | MVP | Cliente exento o producto exento no generan impuesto, y el documento indica por qué. |
| FAC-07 | MVP | Pagos múltiples y mixtos en una misma factura: efectivo con cambio calculado, ATH Móvil, tarjeta, transferencia, cheque y crédito, cada uno con referencia. |
| FAC-08 | MVP | Al emitir, el inventario se descuenta en la misma transacción. Si un renglón no tiene existencia, el sistema avisa y permite o bloquea la venta según la configuración de la empresa. |
| FAC-09 | MVP | Anulación con motivo: la factura nunca se borra, se marca anulada, se revierte el inventario y el número queda ocupado. |
| FAC-10 | MVP | PDF con la marca de la empresa, envío por correo y enlace público de solo lectura para el cliente. |
| FAC-11 | MVP | Impresión en recibo térmico de 80 mm y en tamaño carta, desde la misma factura. |
| FAC-12 | MVP | Pantalla de venta rápida para mostrador: búsqueda o escáner, teclado numérico, cobro e impresión sin salir de la pantalla, en menos de 20 segundos por venta. |
| FAC-13 | MVP | Listado de facturas con filtros por estado, fecha, cliente, vendedor y método de pago, y totales de la vista filtrada al pie. |
| FAC-14 | F2 | Nota de crédito y devolución parcial que reingresa existencia y ajusta el saldo del cliente. |
| FAC-15 | F2 | Cobranza: cálculo de vencimiento por términos de pago y recordatorio automático por correo. |
| FAC-16 | F2 | Propina y cargo por servicio configurables, separados del impuesto. |
| FAC-17 | F3 | Facturas recurrentes con emisión automática y aviso al cliente. |
| FAC-18 | F3 | Cobro con terminal de tarjeta integrada. |
| FAC-19 | MVP | La factura se escribe en una hoja que se parece al documento que recibe el cliente: membrete de la empresa, renglones, nota y pie con el impuesto desglosado. La misma pantalla sirve para escribirla y para consultarla. |
| FAC-20 | MVP | Guardar sin emitir: el borrador no saca número de la serie, no mueve inventario y no cuenta como venta. Se reabre tal como se dejó y se descarta sin dejar rastro. |
| FAC-21 | MVP | Fecha del documento y vencimiento editables, independientes del momento en que se emite, que queda registrado aparte. |
| FAC-22 | MVP | Vendedor y referencia del cliente (número de orden de compra) en la factura, y detalle libre por renglón. |
| FAC-23 | MVP | Vista previa imprimible antes de emitir: lo que se revisa es exactamente lo que se imprime. |
| FAC-24 | MVP | Los desplegables de cliente y de producto ofrecen crearlo al final, en un formulario corto que no obliga a salir de la factura. Se ofrece según el permiso de cada rol. |

## 5.9 Hoja de Cuadre (cierre de caja)

> **Resuelto.** La Hoja de Cuadre real es el cierre de caja por turno, y es más
> simple de lo que suponían `CAJ-01` a `CAJ-10`: cinco cifras escritas a mano,
> una lista de gastos y el efectivo a depositar. Lo construido son `CAJ-11` a
> `CAJ-14`; ver [16-hoja-de-cuadre.md](16-hoja-de-cuadre.md). Los demás
> requisitos de esta sección siguen siendo válidos como mejoras posteriores.

| ID | Prioridad | Requisito |
|---|---|---|
| CAJ-01 | MVP | Apertura de turno por usuario y sucursal, con fondo inicial declarado. No se puede facturar en efectivo sin turno abierto. |
| CAJ-02 | MVP | Cierre que compara, por método de pago, lo esperado según las ventas del turno contra lo declarado por el cajero. |
| CAJ-03 | MVP | Conteo de efectivo con desglose opcional por denominación, que suma automáticamente. |
| CAJ-04 | MVP | Cálculo de sobrante o faltante, con nota obligatoria cuando la diferencia supera el umbral configurado por la empresa. |
| CAJ-05 | MVP | Movimientos de caja del turno: gastos menores, retiros y entradas, con categoría, comentario y comprobante adjunto. |
| CAJ-06 | MVP | Un turno cerrado es inmutable. Cualquier corrección se hace con un movimiento de ajuste que queda registrado y firmado por quien lo autoriza. |
| CAJ-07 | MVP | Hoja de cuadre imprimible y exportable, con ventas por método, impuestos cobrados, gastos, fondo, contado y diferencia. |
| CAJ-08 | F2 | Depósito bancario: monto, banco, referencia y comprobante, ligado a uno o varios turnos. |
| CAJ-09 | F2 | Histórico de cuadres con filtros por fecha, sucursal y usuario, y señal de faltantes recurrentes. |
| CAJ-10 | F2 | Cierre de día que consolida todos los turnos de la sucursal. |
| CAJ-11 | MVP | Hoja de cuadre por fecha y turno (mañana o tarde), con efectivo al comienzo, ventas según lectura, cobrado con tarjeta y con ATH Móvil, efectivo apartado para cambio y lista de compras y gastos. |
| CAJ-12 | MVP | Junto a cada cifra escrita se muestra lo que el sistema facturó en ese turno y la diferencia. Los campos se siguen escribiendo a mano: rellenarlos destruiría el control. |
| CAJ-13 | MVP | Una sola hoja por fecha y turno, garantizado por la base. No se cuadra un turno que todavía no ha pasado. |
| CAJ-14 | MVP | Los totales no se guardan: se calculan siempre desde las cifras escritas, de modo que una hoja no puede mostrar un total que no corresponda a sus propios números. |

## 5.10 Panel y reportes

| ID | Prioridad | Requisito |
|---|---|---|
| RPT-01 | MVP | Panel con información del negocio: ventas del día y del mes comparadas con el periodo anterior, saldo por cobrar, facturas vencidas, valor del inventario, productos bajo mínimo, últimas ventas y los cinco productos más vendidos. |
| RPT-02 | MVP | Cada tarjeta enlaza al listado filtrado que la sustenta; ninguna métrica es un número muerto. |
| RPT-03 | MVP | Rango de fechas global (hoy, 7 días, 30 días, mes, personalizado) que aplica a todo el panel. |
| RPT-04 | MVP | Los widgets de clima y frase del día salen del panel principal; si se conservan, quedan como bloque opcional que el usuario activa. |
| RPT-05 | MVP | Reportes: ventas por periodo, producto, categoría, cliente y vendedor; impuestos cobrados; inventario valorizado; movimientos de inventario; cuentas por cobrar; cuadres de caja; utilidad bruta por producto. |
| RPT-06 | MVP | Todo reporte se exporta a CSV, XLSX y PDF, y el archivo incluye en el encabezado la empresa, el rango y los filtros aplicados. |
| RPT-07 | MVP | Resumen mensual de impuesto cobrado con desglose estatal y municipal, listo para preparar la declaración. |
| RPT-08 | F2 | Panel comparativo entre sucursales. |
| RPT-09 | F3 | Reportes programados que llegan por correo cada semana o cada mes. |

## 5.11 Notificaciones

| ID | Prioridad | Requisito |
|---|---|---|
| NOT-01 | MVP | Centro de notificaciones en la app: producto bajo mínimo, factura vencida, cuadre con diferencia, importación terminada, invitación aceptada. |
| NOT-02 | MVP | Correos transaccionales con la marca de la empresa: factura enviada, recordatorio de pago, invitación, recuperación de clave. |
| NOT-03 | F2 | Preferencias por usuario y por canal, con resumen diario en vez de un correo por evento. |
| NOT-04 | F3 | Envío de factura por WhatsApp o SMS. |
