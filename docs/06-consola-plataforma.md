# 06 · Consola de plataforma

Aplicación aparte, en otro dominio, con su propio login y usuarios que no existen
en las empresas. Es donde se abren las cuentas, se define qué puede hacer cada
una y se atiende soporte.

## Estado actual: la consola ya existe

La consola **AIOP** ya está construida y separada de la app de empresa, con menú
propio bajo la sección ADMINISTRATIVO. La decisión de arquitectura del documento
00 no es un cambio de rumbo: es formalizar lo que ya se hizo y cerrar los huecos.

| Pantalla actual | Contenido | Estado frente a los requisitos |
|---|---|---|
| Home / panel | 1 sesión, 7 usuarios registrados, 11 empresas registradas, más gráficas y una tabla de facturas de la plantilla | Los dos contadores sirven; el resto son datos de demostración (`ADM-21`) |
| Usuarios | Nombres, apellidos, email, empresas, rol, estado, alta y acciones | Cubre `ADM-06` parcialmente. Falta invitación por correo y rol real |
| Empresas | ID, nombre, teléfono, dirección, número de usuarios, estado, alta | Cubre `ADM-01` y `ADM-02` parcialmente. Falta plan, límites y suspensión |
| Roles | Lista de nombres: Administrador, Gerente, Empleado, Contratista, Contador | Solo nombres, sin permisos asociados (`ADM-18`) |
| Métodos Pago | Catálogo con Cheque, ATH Móvil y PayPal | Falta habilitación por empresa y los métodos básicos (`ADM-17`) |

### Hallazgos en los datos y la interfaz

Cada uno tiene su requisito o su tarea de limpieza:

| Hallazgo | Requisito |
|---|---|
| El panel muestra datos de la plantilla: facturas de ejemplo, "Goal: $100000", "Retention: 90%", clientes ficticios | `ADM-21` |
| El contador del panel dice 7 usuarios y el listado muestra 9 | `ADM-21` |
| Cuatro de los usuarios listados no tienen ninguna empresa asignada | `ADM-20` |
| La empresa con ID 12 tiene 0 usuarios | `ADM-19` |
| Nombres de empresa repetidos: 12 y 13 "Evolución Digital", 14 y 15 "Innovacion Digital", ambas con la misma dirección y teléfono | `ADM-23`, `MIG-08` |
| Todos los usuarios tienen rol "Administrador", así que los otros cuatro roles no se usan | `ADM-18`, `MIG-09` |
| Los IDs internos de empresa se muestran como columna en el listado | `ARQ-13` |
| Direcciones y teléfonos de dos países mezclados, con formatos distintos | `ADM-22`, pregunta 1 de `12-limites-y-pendientes.md` |
| Textos en inglés de la plantilla: "Add Record", "Search Invoice", "Select Status", "View Details" | `RNF-05` |
| Botón "Nueva Usuario" en vez de "Nuevo usuario" | `RNF-05` |
| Estado de la empresa se muestra como texto plano, sin distinguir suspendida de activa | `ADM-03` |

## Requisitos

| ID | Prioridad | Requisito |
|---|---|---|
| ADM-01 | MVP | Alta de una cuenta de empresa: datos fiscales, plan, módulos habilitados y correo del propietario inicial, que recibe la invitación al guardar. |
| ADM-02 | MVP | Listado de empresas con estado (prueba, activa, morosa, suspendida, cancelada), plan, número de usuarios, última actividad y consumo frente a sus límites. |
| ADM-03 | MVP | Suspender y reactivar una empresa. Al suspender, la app muestra un aviso claro y bloquea la escritura, pero conserva los datos y permite exportarlos. |
| ADM-04 | MVP | Planes con límites: usuarios, sucursales, productos, facturas por mes y módulos incluidos. Al alcanzar un límite, la app avisa antes de bloquear. |
| ADM-05 | MVP | Interruptores por empresa para Inventario, Facturación y Cuadre: un módulo apagado desaparece del menú, no queda visible y deshabilitado. |
| ADM-06 | MVP | Gestión de usuarios de plataforma con roles, segundo factor obligatorio y registro de accesos. |
| ADM-07 | MVP | Reenviar o revocar la invitación del propietario y reasignar la propiedad de una empresa cuando el dueño pierde el acceso. |
| ADM-08 | MVP | La consola no muestra datos de negocio de una empresa en sus pantallas. El acceso al detalle ocurre solo por impersonación registrada o exportación autorizada. |
| ADM-09 | MVP | Bitácora global consultable por empresa, usuario, acción y fecha, con exportación. |
| ADM-17 | MVP | Catálogo global de métodos de pago administrado en la consola, con habilitación por empresa. El nombre lo fija la plataforma para que los reportes sean comparables entre empresas. Se completan los básicos que hoy faltan: efectivo, tarjeta y transferencia. |
| ADM-18 | MVP | Los roles se administran con su matriz de permisos visible y editable en la consola. Hoy son solo nombres sin permisos asociados. |
| ADM-19 | MVP | No puede existir una empresa sin al menos un usuario propietario: el alta obliga a asignarlo y la consola señala las que quedaron huérfanas. |
| ADM-20 | MVP | Un usuario sin membresía en ninguna empresa no puede iniciar sesión en la app de empresa. La consola los lista y ofrece asignarles empresa o desactivarlos. |
| ADM-21 | MVP | El panel de la consola muestra solo métricas reales de la plataforma. Se retiran los datos de demostración de la plantilla y los contadores cuadran con los listados. |
| ADM-22 | MVP | País, moneda y régimen de impuesto se eligen al crear la empresa y determinan las tasas disponibles y el formato de los documentos. |
| ADM-10 | F2 | Impersonación de un usuario de empresa según `ARQ-14`, con motivo, caducidad y aviso visible. |
| ADM-11 | F2 | Métricas de plataforma: empresas activas, facturas emitidas, uso por módulo, adopción de funciones y errores por empresa. |
| ADM-12 | F2 | Suscripción y cobros: precio, ciclo, historial de pagos, estado de morosidad y suspensión automática configurable. |
| ADM-13 | F2 | Avisos dirigidos a una empresa o a todas, visibles dentro de la app con fecha de expiración. |
| ADM-14 | F2 | Exportación completa de los datos de una empresa (portabilidad) y borrado definitivo con periodo de gracia y confirmación doble. |
| ADM-23 | F2 | Detección de empresas duplicadas por nombre, teléfono y dirección, con acción de fusionar que conserva el historial de las dos. |
| ADM-15 | F3 | Restaurar los datos de una empresa a un punto en el tiempo, sin afectar a las demás. |
| ADM-16 | F3 | Plantillas de cuenta: crear una empresa nueva ya con categorías, impuestos y series típicas del giro. |

## Estados de una cuenta de empresa

| Estado | Qué puede hacer la empresa | Cómo se llega |
|---|---|---|
| Prueba | Todo, con límites del plan de prueba y fecha de fin visible | Alta desde la consola |
| Activa | Todo lo que incluya su plan | Pago confirmado o activación manual |
| Morosa | Todo, con aviso persistente de pago pendiente | Cobro fallido o vencido |
| Suspendida | Solo lectura y exportación de sus datos | Manual, o automática por morosidad |
| Cancelada | Nada. Los datos quedan en periodo de gracia | Baja solicitada o suspensión prolongada |

## Regla de privacidad (ADM-08)

La consola administra cuentas, no datos de negocio. Las pantallas de plataforma
muestran metadatos (cuántos productos, cuándo fue la última factura, cuánto
consume del plan) pero nunca el contenido: ni un producto, ni un cliente, ni una
factura. Cuando soporte necesita ver el detalle, entra por impersonación con
motivo escrito y caducidad, y queda registrado quién vio qué y cuándo.

Esto también aplica a la tabla de facturas que hoy aparece en el panel: aunque
sean datos de la plantilla, ese lugar no es donde deben ir facturas de clientes.
