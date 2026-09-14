# 13 · Seguridad

Este documento reúne todo lo que hay que aplicar. Los requisitos de seguridad que
ya viven en otros documentos se citan, no se repiten: `ARQ-03` a `ARQ-06`
(aislamiento y sesión), `ARQ-11` (bitácora), `ARQ-13` y `ARQ-19` a `ARQ-21`
(identificadores y respuestas), `RNF-06` (base criptográfica), `RNF-07` (pruebas
de aislamiento), `ADM-08` (privacidad en la consola).

## Identidad y sesión

| ID | Prioridad | Requisito |
|---|---|---|
| SEG-01 | MVP | Contraseñas con hash Argon2id (o bcrypt con costo alto si el hosting no ofrece Argon2), mínimo 10 caracteres y rechazo de las filtradas más comunes. |
| SEG-02 | MVP | Sesión por cookie `HttpOnly`, `Secure`, `SameSite=Lax`, con nombre y dominio distintos en cada aplicación. El token de sesión nunca se guarda en `localStorage`. |
| SEG-03 | MVP | Rotación del identificador de sesión al iniciar sesión, al cambiar de empresa y al cambiar la contraseña. |
| SEG-04 | MVP | Cierre de sesión que invalida el token en el servidor, no solo borra la cookie. |
| SEG-05 | MVP | Bloqueo progresivo por intentos fallidos, contado por cuenta y por dirección IP, con aviso al correo del usuario al desbloquear. |
| SEG-06 | MVP | Cambio de contraseña y de correo exigen la contraseña actual y cierran las demás sesiones activas. |
| SEG-07 | MVP | Los enlaces de invitación y de recuperación son de un solo uso, caducan y quedan invalidados al usarse o al emitirse uno nuevo. |
| SEG-08 | MVP | Los mensajes de acceso no revelan si un correo está registrado. |
| SEG-09 | F2 | Segundo factor TOTP con códigos de respaldo de un solo uso; obligatorio para todo usuario de plataforma (`ADM-06`). |
| SEG-10 | F2 | Aviso por correo ante inicio de sesión desde un dispositivo o lugar nuevo. |

## Autorización

| ID | Prioridad | Requisito |
|---|---|---|
| SEG-11 | MVP | Toda ruta niega por defecto: sin política que la permita explícitamente, no responde. No existen endpoints públicos salvo los declarados en la lista de excepciones. |
| SEG-12 | MVP | La empresa activa y el rol se leen de la sesión del servidor en cada petición (`ARQ-05`), y la autorización se resuelve con políticas, no con condicionales repartidos por los controladores. |
| SEG-13 | MVP | Los campos que llegan del cliente se validan contra un esquema explícito y se descarta lo demás. Nunca se asigna en masa lo que venga en la petición. |
| SEG-14 | MVP | Los campos sensibles no son escribibles por API aunque el rol tenga acceso al recurso: empresa, rol, estado de pago, totales calculados, existencia y consecutivos. |
| SEG-15 | MVP | El enlace público de una factura (`FAC-10`) usa un token largo propio del documento, revocable, que solo da acceso a ese documento en modo lectura. |
| SEG-16 | MVP | La impersonación (`ARQ-14`) no permite cambiar contraseñas, invitar usuarios, ni borrar datos: es una sesión de lectura y operación acotada. |

## Datos

| ID | Prioridad | Requisito |
|---|---|---|
| SEG-17 | MVP | Cifrado en tránsito con TLS obligatorio y HSTS; el sitio no responde por HTTP salvo para redirigir. |
| SEG-18 | MVP | Cifrado en reposo de la base de datos y del almacenamiento de archivos. |
| SEG-19 | MVP | Cifrado a nivel de columna para los datos que no se consultan pero se guardan: certificados de exención, referencias bancarias y secretos de segundo factor. |
| SEG-20 | MVP | Los archivos subidos se validan por tipo real y tamaño, se guardan fuera de la raíz web con nombre generado, y se sirven por URL firmada de corta duración. Nunca se ejecuta ni se sirve un archivo subido como código. |
| SEG-21 | MVP | Los respaldos están cifrados y su restauración exige credenciales distintas de las de producción. |
| SEG-22 | MVP | Ni contraseñas, ni tokens, ni datos de clientes aparecen en los registros de la aplicación. Los mensajes de error al usuario no incluyen detalles internos (`RNF-11`). |
| SEG-23 | MVP | Los ambientes de prueba no usan datos reales de clientes; si se copia producción, se anonimiza. |
| SEG-24 | F2 | Purga automática al vencer los plazos de retención (`ARQ-12`, `RNF-14`, `ADM-14`). |

## Aplicación web

| ID | Prioridad | Requisito |
|---|---|---|
| SEG-25 | MVP | Cabeceras de seguridad en las dos apps: `Content-Security-Policy` sin `unsafe-inline`, `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options` o `frame-ancestors`, y `Permissions-Policy`. |
| SEG-26 | MVP | Protección CSRF en toda petición que modifica datos, con la cookie de sesión en `SameSite`. |
| SEG-27 | MVP | CORS restringido a los dominios propios de cada app; sin comodines y sin credenciales hacia orígenes desconocidos. |
| SEG-28 | MVP | Todo texto que viene de la base de datos se escapa al mostrarse. No se inyecta HTML de usuario en el DOM. |
| SEG-29 | MVP | Consultas siempre parametrizadas. Prohibido concatenar entrada del usuario en SQL, y también en los nombres de columna por los que se ordena: se validan contra una lista blanca. |
| SEG-30 | MVP | Límite de tasa por IP y por cuenta en autenticación, búsqueda, exportación e importación, con respuesta clara al usuario cuando lo alcanza. |
| SEG-31 | MVP | Límite de tamaño en peticiones, archivos y resultados de exportación, para que nadie tumbe el servicio con una sola llamada. |
| SEG-32 | MVP | Los PDF y las plantillas de correo se generan con plantillas escapadas; el renderizador de PDF no puede alcanzar la red interna ni el sistema de archivos. |
| SEG-33 | F2 | Reporte de violaciones de CSP recolectado y revisado. |

## Operación y proceso

| ID | Prioridad | Requisito |
|---|---|---|
| SEG-34 | MVP | Secretos fuera del repositorio, inyectados por variables de entorno, distintos por ambiente y rotables sin desplegar código. |
| SEG-35 | MVP | Dependencias con versión fijada y revisión automática de vulnerabilidades en cada integración continua; una vulnerabilidad alta bloquea el despliegue. |
| SEG-36 | MVP | Análisis estático de seguridad en la integración continua, además del de tipos. |
| SEG-37 | MVP | La base de datos y Redis no se exponen a internet; el acceso administrativo pasa por red privada o túnel. |
| SEG-38 | MVP | El usuario de base de datos de la aplicación no es superusuario y no puede alterar las políticas RLS ni el esquema. Las migraciones corren con otro usuario. |
| SEG-39 | MVP | Alertas ante señales de abuso: ráfagas de fallos de acceso, exportaciones masivas, impersonaciones repetidas y errores de aislamiento. |
| SEG-40 | MVP | Ninguna credencial de cliente en manos del equipo: soporte entra por impersonación (`ARQ-14`), nunca pidiendo la contraseña. |
| SEG-41 | F2 | Prueba de penetración externa antes de abrir el registro a clientes nuevos, y repetición anual. |
| SEG-42 | F2 | Plan de respuesta a incidentes escrito: quién decide, cómo se avisa a los clientes afectados y en cuánto tiempo. |

## Lo que se prueba automáticamente

Un requisito de seguridad que nadie verifica se degrada solo. Estas pruebas
corren en cada integración y bloquean el despliegue si fallan:

| Prueba | Cubre |
|---|---|
| Un caso por endpoint intentando leer y escribir datos de otra empresa | `RNF-07`, `ARQ-04`, `SEG-12` |
| Petición a cada endpoint sin sesión y con rol insuficiente | `SEG-11`, `ROL-03` |
| Envío de campos prohibidos en creación y edición | `SEG-13`, `SEG-14` |
| Identificador válido de otra empresa devuelve "no encontrado" | `ARQ-21` |
| Cabeceras de seguridad presentes en las respuestas de las dos apps | `SEG-25` |
| Cookie de una app rechazada por la otra | `ARQ-03`, `SEG-02` |
| Orden por una columna no permitida se rechaza | `SEG-29` |
| Subida de un archivo con extensión falsa se rechaza | `SEG-20` |
| Revisión de dependencias vulnerables | `SEG-35` |
