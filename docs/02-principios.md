# 02 · Principios

Guías que gobiernan las decisiones de diseño e implementación. No son funciones
entregables; son el criterio para resolver las dudas que aparezcan durante la
construcción.

| ID | Prioridad | Requisito |
|---|---|---|
| PR-01 | Guía | La tabla es la pantalla. Cada módulo abre en la lista de datos con búsqueda y filtros; crear y editar ocurre en un panel lateral, no en un formulario permanente arriba. |
| PR-02 | Guía | Nada cambia sin dejar rastro. Existencias, documentos y dinero se mueven por asientos registrados, no por edición directa de un campo. |
| PR-03 | Guía | Cada número lleva a su detalle. Toda métrica del panel es un enlace al listado filtrado que la produce. |
| PR-04 | Guía | Primero el mostrador. Facturar y cuadrar caja se diseñan para pantalla táctil, con teclado y lector de códigos, y funcionan en 390 px de ancho. |
| PR-05 | Guía | Un idioma completo por sesión. Ningún texto queda incrustado en el código; no se mezcla español e inglés en la misma pantalla. |
| PR-06 | Guía | Los datos son del cliente. Exportables en cualquier momento, en formato abierto, sin pedirlo por correo. |
| PR-07 | Guía | El servidor manda. Ocultar un botón no es un permiso; toda regla se verifica en la API. |
| PR-08 | Guía | Errores que enseñan. Cada mensaje dice qué pasó y cómo arreglarlo, en el idioma del usuario y junto al campo que lo causó. |
