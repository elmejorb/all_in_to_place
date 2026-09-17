# 16 · La hoja de cuadre

> Referencia entregada por Luis el 17 de septiembre de 2026, a partir del
> sistema actual. **Construida** ese mismo día (`CAJ-11` a `CAJ-14`).
>
> Esto responde la pregunta 2 de
> [12-limites-y-pendientes.md](12-limites-y-pendientes.md): el cuadre real
> resultó ser bastante más simple de lo que suponían `CAJ-01` a `CAJ-10`.

## Qué es

El cierre de caja de un turno, tal como la panadería ya lo llevaba a mano. Se
anota lo que había en la gaveta al empezar, lo que marcó la registradora, lo
cobrado con tarjeta y con ATH Móvil, lo que se aparta para el cambio de mañana y
lo que se gastó. De ahí sale cuánto efectivo se deposita.

Va en **su propio grupo del menú**, separado de Ventas y de Inventario: no es
una venta ni un producto, es el cierre del turno, y quien entra a hacerlo entra
a hacer solo eso.

## La aritmética

```
Total venta y cambio    = efectivo al comienzo + ventas en efectivo (lectura)
Total efectivo          = total venta y cambio − efectivo para cambio
Total compras y gastos  = suma de los gastos
Efectivo para depositar = total efectivo − gastos
Total ventas            = efectivo para depositar + tarjeta + ATH Móvil
```

**La lectura es solo el efectivo.** Lo cobrado con tarjeta y con ATH Móvil no
sale de la gaveta: no se resta, se suma al final para llegar al total vendido.

Las fórmulas están **comprobadas contra el sistema actual**. Luis metió allá un
turno de prueba y esas mismas cifras son el caso de referencia de
`tests/Unit/CuadreTest.php`:

| | |
|---|---|
| Efectivo al comienzo | 5,000.00 |
| Ventas en efectivo (lectura) | 150,000.00 |
| ATH / Visa / Mastercard | 100,000.00 |
| ATH Móvil | 154,000.00 |
| Efectivo para cambio | 12.00 |
| Compras y gastos | 150.00 |
| **Total venta y cambio** | **155,000.00** |
| **Total efectivo** | **154,988.00** |
| **Efectivo para depositar** | **154,838.00** |
| **Total ventas** | **408,838.00** |

Mientras esa prueba pase, una hoja hecha aquí da exactamente lo mismo que una
hecha allá.

> **Un apunte para revisar algún día, no un error.** El *total de ventas* así
> calculado arrastra el fondo de cambio del comienzo y descuenta los gastos, de
> modo que no coincide con la suma limpia de lo vendido: en el ejemplo da
> 408,838.00 cuando lo vendido fueron 404,000.00. Se reproduce tal cual porque
> es la cifra con la que la panadería lleva años comparando, y cambiarla haría
> que los cierres nuevos no se pudieran cotejar con los viejos.

**Los totales no se guardan, se calculan.** En la base están solo las cinco
cifras que se escriben y los gastos; el depósito sale siempre de la fórmula. Así
no puede existir una hoja cuyos totales no correspondan a sus propios números.

Un depósito negativo no se corrige a cero: se muestra en rojo y con un aviso,
porque significa que falta dinero en la gaveta y eso es justo lo que hay que ver.

## La mejora sobre el papel

La hoja se sigue escribiendo **a mano**, igual que antes. Rellenarla con lo que
el sistema ya sabe ahorraría un minuto y destruiría el control: si alguien no
factura una venta, nadie lo notaría nunca.

Lo que sí hace el sistema es **poner su cifra al lado**:

- Bajo *Ventas en efectivo (según lectura)*, lo cobrado en efectivo según el
  sistema y la diferencia.
- Bajo *ATH/Visa/Mastercard* y *ATH Móvil*, lo cobrado por cada método.
- Cuántas facturas fueron y entre qué horas.

Si cuadra, el texto va en verde; si no, la diferencia sale en rojo. Se ve en el
momento, no tres días después. Lo mismo se imprime en el PDF, de modo que el
papel que se archiva ya lleva el control cruzado hecho.

**El turno se define por la hora**: de mañana hasta el mediodía, de tarde a
partir de ahí, contado en la zona horaria de la empresa —Puerto Rico en el caso
de El Álamo, confirmado el 17/09/2026—. Una hoja nueva se abre en el turno que
se acaba de trabajar.

## Reglas

- **Una hoja por fecha y turno.** Lo impide la base con un índice único, no solo
  el código: dos cierres del mismo turno no cuadran nada.
- **No se cuadra un turno que no ha pasado**, contando el día de la empresa.
- Los gastos se reescriben enteros al guardar. Son cuatro renglones de una
  hoja, no un historial que preservar.
- **Quién puede qué** (matriz del documento 04):

| | Propietario | Administrador | Gerente | Empleado mostrador | Contador |
|---|---|---|---|---|---|
| Cuadrar | Sí | Sí | Sí | Sí | No |
| Ver las de otros | Sí | Sí | Sí | No | Sí |

  Al empleado de almacén no se le ofrece el módulo. El contador la abre en solo
  lectura: los campos salen deshabilitados y no hay botón de guardar.

## El fallo que destapó

Al comparar la hoja con lo facturado apareció algo que llevaba desde el
principio y que nadie habría visto de otra forma: **todas las marcas de tiempo
se guardaban corridas**. Laravel envía las fechas sin desfase y Postgres las
interpretaba en la zona de su sesión, que en esta máquina era Bogotá. Cada
`emitida_en` quedaba cinco horas desplazada.

No se notaba porque nada comparaba instantes; en cuanto el cuadre miró "qué se
facturó entre las 00:00 y las 11:59", salió a la luz. La corrección está en
`config/database.php`: las tres conexiones abren sesión en UTC.

## Lo que falta

| Falta | Requisito |
|---|---|
| Conteo de efectivo por denominación | `CAJ-03` |
| Nota obligatoria cuando la diferencia pasa de un umbral | `CAJ-04` |
| Adjuntar el comprobante de un gasto | `CAJ-05` |
| Hoja inmutable una vez cerrada, con ajustes firmados | `CAJ-06` |
| Depósito bancario ligado a una o varias hojas | `CAJ-08` |
| Cierre de día que consolida los dos turnos | `CAJ-10` |

Hoy una hoja guardada se puede volver a editar. Queda registrado quién la
cuadró, pero no hay bitácora de los cambios: es la decisión a revisar antes de
producción.

## Cómo se probó

- `api/tests/Unit/CuadreTest.php`: la aritmética, escrita antes que la pantalla
  (`CAL-11`).
- `api/tests/Feature/Caja/HojaCuadreTest.php`: la hoja por HTTP, la comparación,
  los permisos y el aislamiento entre empresas.
- `node api/tests/navegador/revisar-cuadre.mjs`: 31 revisiones en un navegador
  real, del menú al PDF, con capturas.
