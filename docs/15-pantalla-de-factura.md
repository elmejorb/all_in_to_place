# 15 · La hoja de factura

> Referencia entregada por Luis el 15 y el 16 de septiembre de 2026, a partir
> del sistema actual y de FreshBooks. **Construida** el 16 de septiembre
> (`FAC-19` a `FAC-23`). Lo que falta está al final.

## La diferencia de fondo

Antes solo había un **panel lateral**: se abría sobre el listado, se llenaba y
se emitía. Lo que pedía la referencia es una **hoja**: la pantalla se parece al
documento que el cliente va a recibir, ocupa el ancho completo y se puede
guardar como borrador antes de emitirse.

No es lo mismo y las dos tienen sentido en momentos distintos, así que están
las dos:

| | Venta rápida (panel) | Hoja (pantalla) |
|---|---|---|
| Para qué | Vender rápido en el mostrador | Armar una factura con calma |
| Quién | Cajero | Quien factura a crédito, a empresas |
| Ritmo | Segundos | Minutos, con borrador |
| Requisito | `FAC-12` | `FAC-19` a `FAC-23` |

En el listado hay un botón para cada una. Al emitir desde el panel se abre la
hoja de la factura recién hecha, de modo que siempre se acaba en el mismo sitio.

## Cómo está hecha

**Una sola pantalla para escribir y para leer.** `HojaFactura.tsx` se dibuja
entera dos veces: con controles mientras es borrador, y en texto cuando ya está
emitida. La vista previa usa esa segunda versión, así que **lo que se revisa
antes de mandar es exactamente lo que se manda**. Tener dos vistas distintas del
mismo documento —una para hacerlo y otra para consultarlo— era justo la
incoherencia que se quería quitar.

**Los campos no se ven hasta que se tocan.** Una hoja con veinte cajas dibujadas
se lee como un formulario; con el texto puesto donde va se lee como una factura,
que es lo que hay que poder revisar de un vistazo.

**Los totales los calcula el servidor mientras se escribe**, con el mismo código
que emite (`POST /facturas/calcular`). Lo que se ve es lo que se va a cobrar.

### Las dos vidas de una factura

Mientras es **borrador** se cambia entera, no tiene número y no ha movido
inventario: no existe para nadie más que para quien la escribe. Al **emitirse**
saca número de la serie, descuenta la mercancía y se congela; desde ahí solo se
cobra o se anula (`FAC-09`).

- Emitir un borrador **conserva su identificador**, así que un enlace abierto
  sigue llevando al mismo documento.
- Descartar un borrador lo borra de verdad y **no gasta número** (`ARQ-08`).
- Los borradores **no cuentan como facturado** en el resumen del listado: la
  cifra que se mira para saber cómo va el día no se infla con lo que todavía no
  se ha vendido.
- Un documento emitido no se borra. Lo impide un disparador de la base, que
  revienta; una política de permisos habría borrado cero filas sin avisar, y un
  borrado silencioso es peor que un error.

### Lo que se añadió al modelo

| Campo | Por qué |
|---|---|
| `documento.fecha` | La fecha del documento, editable. `emitida_en` sigue siendo el sello de cuándo ocurrió: una factura fechada el día 1 puede haberse emitido el día 3, y las dos cosas son ciertas |
| `documento.vendedor` | Texto libre, no un usuario: quien vende en el mostrador no siempre tiene cuenta, y quien la teclea ya queda en `usuario_id` |
| `documento.referencia` | El número de orden de compra del cliente, que es lo que él busca cuando llama |
| `documento_renglon.detalle` | La descripción ampliada: el producto dice "Torta chocolate" y el detalle dice "con el nombre en letra azul" |

### Tres cosas que se corrigieron de la referencia

1. **La columna "Costo" ahora dice "Precio".** En la referencia esa columna es
   lo que se le cobra al cliente, no lo que cuesta la mercancía. Con un costo de
   verdad en el catálogo, el nombre viejo se prestaba a confusión.

2. **El pie desglosa el impuesto.** La referencia solo mostraba Subtotal y
   Total; `FAC-04` pide estatal y municipal por separado, porque es lo que se
   declara. El cálculo ya existía y ahora se ve.

3. **El descuento está en la hoja.** No aparecía en la referencia, pero el
   sistema lo calcula por renglón y global desde el principio. Va escondido
   detrás de un enlace —"Agregar un descuento"— para no ensuciar la hoja de
   quien no lo usa.

### Y una que se descubrió construyendo

Al empleado de **mostrador** se le pedía facturar pero no se le dejaba ver el
catálogo, así que el desplegable de productos le salía vacío: no podía vender.
Ver la lista de productos es ahora un permiso propio (`productos.ver`,
`ROL-11`), separado de ver suplidores, categorías y costos.

## Lo que falta

| Falta | Requisito |
|---|---|
| PDF con la marca de la empresa y envío por correo | `FAC-10` |
| Enlace público de solo lectura para el cliente | `FAC-10` |
| Impresión en recibo térmico de 80 mm | `FAC-11` |
| Subir el logotipo de la empresa (la hoja ya lo dibuja si existe) | `EMP-01` |
| Crear un cliente sin salir de la hoja | `CLI-02` |
| Nota de crédito y devolución parcial | `FAC-14` |

La vista previa ya imprime con los estilos de impresión puestos, así que es la
base sobre la que se hará el PDF.

## Cómo se probó

- `api/tests/Feature/Facturacion/BorradorFacturaTest.php`: la vida entera del
  borrador, el aislamiento entre empresas y los permisos.
- `node api/tests/navegador/revisar-hoja-factura.mjs`: 46 revisiones en un
  navegador real, de la hoja en blanco a la factura anulada, con capturas.
- `node api/tests/navegador/revisar-facturacion.mjs`: la venta rápida, que
  sigue funcionando y acaba en la hoja.
