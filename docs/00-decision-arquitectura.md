# 00 · Decisión de arquitectura: dos aplicaciones, una plataforma

## Recomendación

Sí se puede hacer en el mismo proyecto, pero **no en la misma aplicación**.
La forma recomendada: **un solo repositorio y una sola API/base de datos, con dos
aplicaciones web independientes** — la app que usan las empresas y la consola con
la que se crean y administran esas cuentas. Cada una con su propio dominio, su
propio login y su propio conjunto de rutas.

Así se comparte lo que conviene compartir (modelo de datos, reglas de impuestos,
componentes de interfaz, despliegue) y se separa lo que nunca debe mezclarse:
sesiones, permisos, menús y pantallas. Un usuario de empresa no tiene forma de
llegar a una pantalla de administración, ni siquiera escribiendo la URL.

```
+------------------------------+   +------------------------------+
|  App de la empresa           |   |  Consola de plataforma       |
|  app.dominio                 |   |  admin.dominio               |
|  Inventario / Facturación    |   |  Cuentas / Planes y límites  |
|  Hoja de Cuadre / Reportes   |   |  Soporte / Métricas          |
+------------------------------+   +------------------------------+
      login, cookies y rutas separadas: ningún enlace cruza
+------------------------------------------------------------------+
|  API /v1 versionada · reglas de negocio compartidas              |
|  PostgreSQL con aislamiento por empresa_id (Row Level Security)  |
+------------------------------------------------------------------+
```

## Reparto de responsabilidades

| Responsabilidad | App de la empresa | Consola de plataforma |
|---|---|---|
| Crear una cuenta de empresa | No | Sí |
| Editar datos y preferencias de la empresa | Sí | Solo soporte |
| Invitar usuarios y asignar roles | Sí | Solo el propietario inicial |
| Plan, límites y módulos habilitados | Ver, no editar | Sí |
| Suspender o reactivar una empresa | No | Sí |
| Operación diaria (productos, facturas, cuadre) | Sí | No |
| Ver datos de negocio de un cliente | Sí | Solo por impersonación registrada |
| Bitácora global y métricas de uso | No | Sí |

## Por qué no un rol "superadmin" en la misma app

Es la alternativa más rápida de construir y la que sale más caro después:

- Un error de permisos en un endpoint expone datos de **todos** los clientes,
  no de uno.
- El menú y las pantallas se llenan de opciones que no aplican al usuario, y la
  interfaz de operación diaria se contamina con funciones de plataforma.
- No se puede desplegar, auditar ni limitar el acceso por separado. Soporte
  necesita entrar a producción con una cuenta que también puede facturar.
- Las métricas de uso y la bitácora global quedan mezcladas con los datos del
  negocio, lo que complica exportar o borrar los datos de un cliente.

## Lo que sí se comparte

- El modelo de datos y las migraciones (`db`).
- Las reglas de negocio: impuestos, totales, existencias, numeración (`core`).
- Los componentes de interfaz y los tokens de diseño (`ui`), con identidad visual
  distinta en cada app para que nadie confunda dónde está trabajando.
- La infraestructura: base de datos, colas, almacenamiento de archivos, CI/CD.
