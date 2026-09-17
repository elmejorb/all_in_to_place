# 07 · Rediseño de interfaz

La estructura visual del sistema actual es correcta en lo grande — barra superior
con empresa, menú lateral por módulo, contenido a la derecha — y se conserva. Los
cambios son de mecánica de uso.

## Cambios concretos frente a las pantallas actuales

| Hoy | En v2 |
|---|---|
| Formulario fijo arriba y tabla abajo en cada módulo | La tabla ocupa la pantalla; crear y editar abre un panel lateral con "Guardar y crear otro" |
| Botones "Guardar / Nuevo" sin respuesta visible | Botón con estado de carga, aviso de confirmación y la fila nueva resaltada en la tabla |
| Tablas sin buscador, orden ni paginación | Búsqueda instantánea, filtros guardados en la URL, orden por columna, paginación en servidor y densidad ajustable |
| Menú "⋮" sin etiquetas ni confirmación | Acciones con nombre e icono, atajos de teclado, y confirmación que dice exactamente qué se va a borrar |
| Encabezados recortados: "Exit. Min", "Tax.%" | Nombres completos, con unidad y ayuda en línea donde hace falta |
| Español e inglés mezclados en la misma pantalla | Un idioma completo por sesión, con todos los textos en catálogo |
| Sin estados vacíos ni validación | Estado vacío con acción sugerida ("Aún no hay productos · Importar plantilla") y validación junto al campo sin perder lo escrito |
| Clima y frase del día ocupan media pantalla | Ese espacio pasa a ventas del día, cobros pendientes y existencias críticas |
| Tablas anchas que no caben en móvil | Filas apiladas en tarjeta bajo 640 px y columna de nombre fija al desplazar en escritorio |
| Selector de empresas con identificadores internos y nombres repetidos | Buscador con iniciales, rol del usuario, empresa actual marcada y aviso de nombres duplicados al crear |
| Casillas de selección sin acciones en lote | Barra de acciones que aparece con la selección y dice cuántos registros va a afectar |
| Sin foco visible ni navegación por teclado | Foco visible, recorrido completo por teclado, contraste AA y etiquetas asociadas a cada campo |

## Requisitos

| ID | Prioridad | Requisito |
|---|---|---|
| IU-01 | MVP | Un solo sistema de diseño compartido por las dos aplicaciones: tokens de color, tipografía y espaciado, más componentes de tabla, formulario, panel lateral y aviso. |
| IU-02 | MVP | La consola usa los mismos componentes con una identidad visual distinta, para que nadie confunda en qué aplicación está. |
| IU-03 | MVP | Tema claro y oscuro completos, que respetan la preferencia del sistema y recuerdan la elección del usuario. |
| IU-04 | MVP | Un solo formato monetario y numérico, con cifras alineadas en columna en todas las tablas. |
| IU-05 | F2 | Buscador global con atajo de teclado que encuentra productos, clientes, facturas y pantallas. |
| IU-06 | F2 | Guía de primeros pasos por empresa: configurar datos, cargar productos, emitir la primera factura. |
| IU-07 | F3 | Panel personalizable: el usuario elige qué tarjetas ve y en qué orden. |

## Patrones que se repiten en todos los módulos

Definirlos una vez ahorra decisiones y mantiene la app coherente:

1. **Listado.** Título, buscador, filtros, botón primario de alta, tabla con
   orden y paginación, y barra de acciones en lote que aparece con la selección.
2. **Panel lateral de captura.** Se abre sobre el listado, no navega a otra
   página. Cierra con Escape, avisa si hay cambios sin guardar, y ofrece
   "Guardar" y "Guardar y crear otro".
3. **Estado vacío.** Explica qué es el módulo en una línea y ofrece la acción que
   corresponde: crear el primero, o importar.
4. **Confirmación destructiva.** Dice el nombre de lo que se va a afectar y qué
   consecuencia tiene. Si el registro tiene historial, ofrece desactivar en vez
   de borrar.
5. **Aviso de resultado.** Toda acción responde: confirmación breve al guardar,
   mensaje junto al campo al fallar la validación, y aviso con identificador de
   error cuando falla el servidor.

## Quién va encima de quién

Los `z-index` salen todos de unas variables en `tokens.css` y **no se escriben
sueltos en ninguna hoja de estilos**. Repartirlos por los archivos es cómo se
acaba con una barra pegajosa de una pantalla tapando el menú de la barra
superior, que es exactamente lo que pasó con la hoja de factura.

| Capa | Variable |
|---|---|
| Barras pegajosas dentro de una pantalla | `--z-pantalla` |
| Menú lateral | `--z-rail` |
| Barra superior, y los desplegables que cuelgan de ella | `--z-barra` |
| Velo y cajón del menú en móvil | `--z-velo`, `--z-cajon` |
| Panel lateral de captura | `--z-panel` |
| Vista previa de un documento | `--z-previa` |
| Diálogo corto | `--z-dialogo` |
| Avisos | `--z-avisos` |

La regla de fondo: **lo que es de la aplicación va por encima de lo que es de la
pantalla**. Una barra que se pega dentro del contenido se aparca debajo de la
barra superior, nunca sobre ella.

`revisar-hoja-factura.mjs` lo comprueba abriendo el selector de empresa desde una
pantalla con barra pegajosa, porque es un fallo que no se ve hasta que alguien
hace justo eso.

## Movimiento

Las transiciones son cortas —de 120 a 180 ms— y sirven para explicar de dónde
sale algo: el menú desplegable nace desde su botón, la punta gira, y la barrita
de la izquierda crece al pasar por encima sin mover nada de sitio. Todas se
apagan con `prefers-reduced-motion`. Nada se mueve porque sí.
