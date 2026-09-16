# 04 · Roles y permisos

Dos conjuntos de roles que no se cruzan: los de la empresa (asignados por
membresía, de modo que un usuario puede tener roles distintos en empresas
distintas) y los de la plataforma.

## Roles de empresa

Se conservan los cinco nombres que ya existen en la consola —Administrador,
Gerente, Empleado, Contratista, Contador— para no cambiarle el vocabulario a
quien ya usa el sistema, y se agrega **Propietario**, que hoy falta y es lo que
permite exigir un responsable por cuenta (`ROL-02`, `ADM-19`).

| Rol | Para quién | Es nuevo |
|---|---|---|
| Propietario | El dueño de la cuenta. Único que configura la empresa y puede ceder la propiedad | Sí |
| Administrador | Gerente general o encargado con mando completo de la operación | No |
| Gerente | Encargado de tienda o de turno. Opera y ve resultados, no configura | No |
| Empleado | Mostrador o almacén, según el perfil con el que se lo invite (`ROL-08`) | No |
| Contratista | Colaborador externo. Solo ve y factura lo suyo | No |
| Contador | Solo lectura de reportes, costos e impuestos | No |

> Hoy los nueve usuarios de la consola tienen rol "Administrador", así que los
> otros cuatro no se usan. Reasignar el rol real de cada persona es parte de la
> migración (`MIG-09`).

## Matriz de permisos

`Sí` permitido · `Limit.` con restricciones, detalladas debajo · `No` sin acceso.

| Capacidad | Propietario | Administrador | Gerente | Empleado | Contratista | Contador |
|---|---|---|---|---|---|---|
| Configurar la empresa y las series | Sí | Limit. | No | No | No | No |
| Invitar usuarios y cambiar roles | Sí | Limit. | No | No | No | No |
| Ver la lista de productos y precios | Sí | Sí | Sí | Sí | No | Sí |
| Crear y editar productos y precios | Sí | Sí | Sí | Limit. | No | No |
| Recibir compras y ajustar existencias | Sí | Sí | Sí | Limit. | No | No |
| Ver clientes | Sí | Sí | Sí | Limit. | Sí | Sí |
| Crear y editar clientes | Sí | Sí | Sí | Limit. | No | No |
| Emitir facturas y cobrar | Sí | Sí | Sí | Limit. | Limit. | No |
| Anular factura o aplicar nota de crédito | Sí | Sí | Limit. | No | No | No |
| Abrir y cerrar turno de caja | Sí | Sí | Sí | Limit. | No | No |
| Ver el cuadre de otros usuarios | Sí | Sí | Sí | No | No | Limit. |
| Ver costos y márgenes | Sí | Sí | Sí | No | No | Sí |
| Reportes y exportaciones | Sí | Sí | Sí | Limit. | Limit. | Sí |
| Ver la bitácora de la empresa | Sí | Limit. | No | No | No | No |

Aclaraciones de los `Limit.`:

- **Administrador / configurar:** edita datos y preferencias, no las series de
  facturación ni la configuración fiscal.
- **Administrador / invitar:** no puede crear ni degradar a un Propietario.
- **Administrador / bitácora:** ve la de operación, no la de accesos y sesiones.
- **Gerente / anular:** solo facturas del día en curso; las anteriores las anula
  un Administrador.
- **Empleado / ver productos:** los dos perfiles ven la lista con sus precios.
  Es un permiso aparte del resto del catálogo (`productos.ver`) porque quien
  está en el mostrador no puede vender lo que no puede buscar, pero tampoco
  tiene por qué ver suplidores, categorías ni costos.
- **Empleado / editar productos:** con perfil de almacén edita costo, existencia
  mínima y datos logísticos; nunca el precio de venta.
- **Empleado / existencias:** solo con perfil de almacén.
- **Empleado / facturar:** solo con perfil de mostrador. Vende, pero no anula
  una venta ya emitida.
- **Empleado / clientes:** solo con perfil de mostrador, porque sin clientes
  no puede facturar a quien llega por primera vez (CLI-02).
- **Empleado / caja:** abre y cierra su propio turno, no el de otros.
- **Empleado y Contratista / reportes:** solo los de su propia actividad.
- **Contratista / facturar:** solo documentos propios, y solo los ve él y sus
  superiores.
- **Contador / cuadres:** ve los cuadres cerrados, no puede abrir ni ajustar.

## Requisitos

| ID | Prioridad | Requisito |
|---|---|---|
| ROL-01 | MVP | Roles predefinidos con permisos fijos en el MVP; roles personalizados por empresa más adelante. |
| ROL-02 | MVP | Toda empresa tiene al menos un Propietario activo; el sistema impide quitar el último. |
| ROL-03 | MVP | Los permisos se verifican en el servidor por endpoint. Ocultar un botón en la interfaz no cuenta como permiso. |
| ROL-04 | MVP | Roles de plataforma: Superadmin (todo), Soporte (lectura más impersonación con motivo), Comercial (cuentas, planes y cobros, sin datos de negocio). |
| ROL-05 | MVP | El cambio de rol de un usuario toma efecto en su siguiente petición, sin necesidad de que cierre sesión. |
| ROL-06 | MVP | Toda asignación y cambio de rol queda en la bitácora con quién lo hizo. |
| ROL-07 | MVP | Cada rol trae su matriz de permisos visible en la consola, no solo su nombre. |
| ROL-08 | MVP | El rol Empleado se acota con un perfil al invitar al usuario: mostrador o almacén. Un mismo usuario puede tener los dos. |
| ROL-09 | MVP | El rol se asigna por empresa, no por usuario: la misma persona puede ser Propietario en una y Empleado en otra. |
| ROL-10 | F3 | Roles personalizados con permisos granulares por módulo. |
| ROL-11 | MVP | Ver la lista de productos es un permiso propio, separado del resto del catálogo: quien factura la necesita aunque no administre el inventario. |
