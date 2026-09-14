# 08 · Requisitos no funcionales

| ID | Prioridad | Requisito |
|---|---|---|
| RNF-01 | MVP | Primera carga útil por debajo de 2 segundos y respuesta de listados por debajo de 400 ms en el percentil 95, con 50 000 productos y 200 000 facturas por empresa. |
| RNF-02 | MVP | Ninguna pantalla carga una tabla completa en memoria; todo listado pagina y filtra en el servidor. |
| RNF-03 | MVP | Uso completo en móvil, tableta y escritorio. Facturar y cuadrar son operables con una mano a 390 px de ancho. |
| RNF-04 | MVP | Accesibilidad WCAG 2.1 AA: contraste, foco visible, recorrido por teclado, etiquetas para lector de pantalla y respeto a "reducir movimiento". |
| RNF-05 | MVP | Español e inglés desde el primer día, con formatos de fecha, número y moneda por configuración regional. |
| RNF-06 | MVP | Seguridad: contraseñas con hash Argon2id, tokens de vida corta, protección CSRF, límite de tasa por IP y por cuenta, cabeceras CSP y HSTS, cifrado en tránsito y en reposo. |
| RNF-07 | MVP | El aislamiento entre empresas se verifica con pruebas automáticas en cada despliegue: por cada endpoint, un intento de leer o escribir datos de otra empresa debe fallar. |
| RNF-08 | MVP | Pruebas unitarias obligatorias del núcleo: cálculo de impuestos, totales, existencias, numeración de documentos y cuadre de caja. Pruebas de extremo a extremo de facturar, importar y cerrar turno. |
| RNF-09 | MVP | Respaldo diario con retención de 30 días, recuperación a un punto en el tiempo y prueba de restauración cada trimestre. Objetivo: perder como máximo 1 hora de datos y restablecer el servicio en 4 horas. |
| RNF-10 | MVP | Disponibilidad objetivo del 99.5% mensual, con página de estado y ventanas de mantenimiento anunciadas. |
| RNF-11 | MVP | Registro estructurado, trazas y alertas. Todo error mostrado al usuario incluye un identificador que soporte puede buscar. |
| RNF-12 | MVP | Los documentos emitidos son inmutables: el PDF de una factura emitida hoy es idéntico si se descarga en dos años. |
| RNF-13 | MVP | Migraciones de base de datos versionadas, reversibles y aplicadas automáticamente en el despliegue. |
| RNF-14 | MVP | Retención de bitácora de 24 meses como mínimo, sin posibilidad de edición. |
| RNF-15 | F2 | Tolerancia a conexión intermitente en la pantalla de venta: la venta en curso no se pierde y se envía al recuperar la conexión. |
| RNF-16 | F2 | Ambiente de pruebas con datos ficticios donde el equipo y los clientes nuevos puedan practicar sin tocar producción. |
| RNF-17 | F3 | Métricas de negocio por empresa expuestas por API para integraciones del cliente. |

## Cómo se comprueba cada uno

Un requisito no funcional sin forma de medirlo no sirve. Esto es lo que se
revisa antes de dar por buena una entrega:

| Requisito | Comprobación |
|---|---|
| RNF-01, RNF-02 | Prueba de carga con datos sintéticos al volumen indicado, en la integración continua |
| RNF-03 | Recorrido manual de facturar y cuadrar en un teléfono real de 390 px |
| RNF-04 | Auditoría automática de accesibilidad más revisión por teclado de los flujos principales |
| RNF-05 | Verificación de que no quedan textos fuera del catálogo de traducción |
| RNF-06 | Revisión de dependencias, análisis estático y prueba de penetración antes de salir a producción |
| RNF-07 | Suite de aislamiento que falla el despliegue si un endpoint filtra datos |
| RNF-08 | Cobertura mínima acordada en el paquete `core`, sin excepciones |
| RNF-09 | Restauración real en ambiente aparte cada trimestre, con acta del resultado |
| RNF-12 | El PDF se guarda al emitir y se sirve desde el archivo, no se vuelve a generar |
