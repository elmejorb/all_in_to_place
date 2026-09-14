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
