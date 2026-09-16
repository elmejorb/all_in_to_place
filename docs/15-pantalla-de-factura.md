# 15 · Pantalla de factura: cómo debe verse

> Referencia entregada por Luis el 15 de septiembre de 2026, a partir de la
> pantalla del sistema actual. **Pendiente de construir.** Lo que hay hoy en v2
> es un panel lateral (`NuevaFactura.tsx`), que sirve para una venta rápida pero
> no es esto.

## La diferencia de fondo

Lo que hay hoy es un **panel lateral**: se abre sobre el listado, se llena y se
emite. Lo que pide la referencia es una **hoja**: la pantalla se parece al
documento que el cliente va a recibir, ocupa el ancho completo y se guarda como
borrador antes de emitirse.

No es lo mismo y las dos tienen sentido en momentos distintos:

| | Panel lateral (lo que hay) | Hoja (lo que se pide) |
|---|---|---|
| Para qué | Vender rápido en el mostrador | Armar una factura con calma y enviarla |
| Quién | Cajero | Quien factura a crédito, a empresas |
| Ritmo | Segundos | Minutos, con borrador |
| Requisito | `FAC-12` (venta rápida) | `FAC-01`, `FAC-09`, `FAC-10` |

**Conviene tener las dos**, no elegir. La hoja es la pantalla principal de
Facturación; el panel se queda para la venta de mostrador.

## Lo que muestra la referencia

**Estructura:** hoja blanca a la izquierda ocupando casi todo el ancho, y una
tarjeta de acciones flotante a la derecha con tres botones:
**Enviar Factura**, **Vista Previa** y **Guardar**.

**Encabezado de la hoja**

- Logo y nombre de la empresa arriba a la izquierda, con dirección y teléfono
  debajo.
- A la derecha: la palabra *Invoice*, el número de factura (`# 0`), **Date** y
  **Due Date**, las dos editables.

**Cuerpo**

- **Cliente**: un desplegable, solo.
- **Tabla de renglones** con columnas: Descripción, Costo, Cant., Tax (%),
  Línea total, y un icono de papelera para quitar el renglón.
  - La celda de Descripción trae **dos controles**: un desplegable de producto
    y, debajo, un área de texto libre para describirlo.
  - El **Tax va por renglón y es editable**, con su símbolo de porcentaje.
- Botón **+ Add Item** debajo de la tabla.

**Pie**

- **Salesperson** (vendedor) a la izquierda, como campo de texto.
- **Subtotal** y **Total** a la derecha.
- **Nota**: área de texto al final de la hoja.

## Qué hay que construir

| Falta | Nota |
|---|---|
| La hoja completa como pantalla, no como panel | Es el trabajo grueso |
| **Guardar como borrador** | El estado `borrador` ya existe en la base y en `FAC-02`, pero no hay forma de llegar a él: hoy se emite directo |
| **Vista previa** | Antes del PDF (`FAC-10`), sirve una vista de impresión |
| **Enviar factura** | Necesita correo y PDF: `FAC-10` |
| **Fecha y vencimiento editables** | Hoy la fecha es la de emisión y el vencimiento sale de los términos de pago |
| **Vendedor** | No existe en el modelo. Hay que decidir si es texto libre o un usuario de la empresa |
| **Nota en el documento** | El campo `notas` ya está en la tabla `documento`; falta ponerlo en pantalla |
| **Logo y datos de la empresa en la hoja** | `EMP-01` ya guarda el logo; falta mostrarlo |
| Área de texto libre por renglón | Hoy la descripción se copia del producto y no se puede ampliar |

## Tres cosas que revisar antes de copiarlo tal cual

1. **La columna dice "Costo" pero es el precio de venta.** En el sistema actual
   esa columna es lo que se le cobra al cliente. Llamarla costo se presta a
   confusión justo cuando ya existe un costo de verdad en el catálogo. Debería
   decir **Precio**.

2. **El pie solo muestra Subtotal y Total.** Falta el impuesto, y `FAC-04` pide
   que vaya desglosado —en El Álamo, estatal y municipal— porque es lo que se
   declara. El cálculo ya lo hace; hay que mostrarlo.

3. **No hay descuento en la referencia.** El sistema ya calcula descuento por
   renglón y global. Si de verdad no se usan, se pueden esconder; si se usan,
   la hoja tiene que tener dónde ponerlos.

## Por dónde empezar

1. La hoja con lo que ya existe: encabezado con datos de la empresa, cliente,
   renglones, nota, y el pie con el desglose del impuesto.
2. Guardar como borrador y volver a abrirlo.
3. Vista previa imprimible, que es la base del PDF.
4. Vendedor, y fecha y vencimiento editables.
5. Enviar por correo, ya con el PDF (`FAC-10`).
