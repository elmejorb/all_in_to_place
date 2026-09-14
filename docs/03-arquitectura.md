# 03 · Arquitectura y multi-empresa

| ID | Prioridad | Requisito |
|---|---|---|
| ARQ-01 | MVP | Monorepo con paquetes separados: `app-empresa`, `consola-admin`, `api`, `core` (reglas de negocio), `ui` (componentes y tokens), `db` (esquema y migraciones). |
| ARQ-02 | MVP | Dos hosts distintos. Ninguna ruta de la consola es alcanzable desde la app de empresa ni al revés, ni escribiendo la URL directamente. |
| ARQ-03 | MVP | Dos ámbitos de autenticación independientes: una sesión de la consola no es válida en la app de empresa, y viceversa. Cookies con nombre, dominio y firma distintos. |
| ARQ-04 | MVP | Toda tabla de negocio lleva `empresa_id` y el filtrado se aplica en la capa de datos (Row Level Security de PostgreSQL), no solo en la consulta de la aplicación. |
| ARQ-05 | MVP | La empresa activa se resuelve desde la sesión del servidor, nunca desde un parámetro enviado por el navegador. |
| ARQ-06 | MVP | Cambiar de empresa emite una sesión nueva y descarta toda caché de datos en el cliente. |
| ARQ-07 | MVP | API única versionada. Los endpoints de plataforma viven bajo `/v1/admin/*` y exigen un rol de plataforma; los de negocio exigen membresía en la empresa. |
| ARQ-08 | MVP | Numeración de documentos por empresa y por serie, con secuencia transaccional sin huecos ni repetidos, incluso con dos cajeros emitiendo a la vez. |
| ARQ-09 | MVP | Todo importe se guarda como entero en centavos. Ningún cálculo de dinero usa coma flotante. |
| ARQ-10 | MVP | Los cortes de día usan la zona horaria de la empresa (por defecto `America/Puerto_Rico`), no la del servidor ni la del navegador. |
| ARQ-11 | MVP | Bitácora append-only de creación, edición y anulación en documentos, inventario, usuarios y configuración: quién, cuándo, desde dónde, valor anterior y nuevo. |
| ARQ-12 | MVP | Borrado lógico con retención. Ningún registro de negocio se elimina físicamente por una acción de usuario. |
| ARQ-13 | MVP | Identificadores públicos largos y no adivinables (ULID o UUID v7) en empresa, usuario, producto, cliente, documento y todo recurso alcanzable por la API. La clave incremental existe solo como índice interno de la base: no aparece en URLs, respuestas, exportaciones ni pantallas. |
| ARQ-14 | F2 | Impersonación desde la consola: requiere motivo escrito, caduca en 60 minutos, queda en bitácora y muestra un aviso fijo en pantalla mientras está activa. |
| ARQ-15 | F2 | Clave de idempotencia obligatoria al crear documentos y pagos, para que un doble clic o un reintento de red no duplique una factura. |
| ARQ-16 | F2 | Cola de trabajos para importaciones, exportaciones grandes, correos y reportes pesados, con estado visible para el usuario. |
| ARQ-18 | MVP | Las reglas de negocio viven en `app/Domain`, sin dependencias del framework, con análisis estático estricto y pruebas propias. |
| ARQ-19 | MVP | Enumerar recursos no revela cuántos hay ni permite recorrerlos: la paginación usa cursor opaco, no un índice numérico sobre identificadores. |
| ARQ-20 | MVP | Cada respuesta de la API expone solo los campos que la pantalla necesita, a través de recursos explícitos. Nunca se serializa el modelo completo. |
| ARQ-21 | MVP | Un identificador que no pertenece a la empresa de la sesión responde "no encontrado", nunca "sin permiso", para no confirmar que existe. |
| ARQ-17 | F3 | Réplica de lectura para reportes, de modo que un reporte pesado no afecte la velocidad de la caja. |

## Notas de implementación

### Aislamiento por empresa (ARQ-04, ARQ-05)

Cada petición autenticada establece la variable de sesión de PostgreSQL con la
empresa de la membresía activa, y las políticas RLS filtran por ella. Si un
endpoint olvida el filtro en su consulta, la base de datos igual no devuelve
filas de otra empresa. Esto se verifica con las pruebas de `RNF-07`.

### Numeración de documentos (ARQ-08)

La secuencia vive en la tabla `serie_documento` y se incrementa dentro de la
misma transacción que inserta el documento, con bloqueo de fila. No se usa una
secuencia global de la base de datos, porque cada empresa y cada serie necesita
su propio contador continuo.

### Identificadores (ARQ-13, ARQ-19, ARQ-21)

Cada tabla lleva dos claves:

- `id` — `bigint` autoincremental. Es la clave primaria y la que usan las
  llaves foráneas y los índices. **No sale nunca** de la base de datos.
- `ulid` — cadena de 26 caracteres, única y no adivinable. Es el identificador
  público: el que va en la URL, en la respuesta de la API, en el enlace público
  de una factura y en los archivos exportados.

Se prefiere ULID sobre UUID v4 porque conserva el orden temporal, así que los
índices no se fragmentan y no hace falta una columna extra para ordenar por
antigüedad. En Laravel es un trait de un par de líneas sobre el modelo, con la
resolución de ruta apuntando a `ulid`.

Por qué importa más allá de la estética: con identificadores incrementales
visibles, quien tenga una cuenta puede recorrer `1, 2, 3...` y medir cuántas
empresas, clientes o facturas existen, y cada intento fallido confirma qué
identificadores están ocupados. Con ULID no hay nada que recorrer, y `ARQ-21`
cierra el resto respondiendo igual para "no existe" y "no es tuyo".

El número de factura visible para el cliente (`ARQ-08`) es otra cosa: ese sí es
consecutivo y legible, porque tiene que serlo, pero es un dato del documento, no
su identificador de API.

### Dinero (ARQ-09)

Los importes se guardan como `bigint` en centavos y se formatean solo al
mostrarlos. Los porcentajes de impuesto y descuento se guardan como entero en
milésimas (11.5% = `11500`) para evitar redondeos acumulados.
