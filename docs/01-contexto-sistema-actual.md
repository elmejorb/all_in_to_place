# 01 · Contexto: qué existe hoy

El sistema actual ya cubre el esqueleto del negocio y sirve como referencia de
alcance mínimo: selector de empresa en la barra superior (4 empresas), menú con
**Inventario** (Suplidor, Categorías, Productos), **Facturación** y **Hoja de
Cuadre**, tema claro/oscuro, cambio de idioma y panel con métricas.

## Resuelto

- Catálogo de productos con costo, existencia, existencia mínima, impuesto
  (7% y 11.5%), categoría y suplidor.
- Marca de "servicio" para productos sin inventario.
- Importar y exportar por plantilla.
- Creación de empresas y configuración básica (compañía, teléfono, dirección).
- Panel con total de inventario, facturas pendientes, productos agotados,
  número de suplidores y de clientes, total facturado del mes, línea de tiempo y
  gráfica de ventas de los últimos 6 meses.

## Se queda corto

Esto es lo que motiva la reconstrucción. Cada punto tiene su requisito
correspondiente en los documentos siguientes.

| Observación | Requisito que lo resuelve |
|---|---|
| No hay precio de venta ni margen, solo costo | `PRO-01`, `PRO-02` |
| La existencia se edita a mano sin dejar rastro | `INV-01`, `INV-02`, `INV-03` |
| Las tablas no tienen buscador, orden ni paginación | `PRO-05`, `PRO-06`, `SUP-03` |
| Los formularios no validan y pierden lo escrito | `SUP-02`, `IU-01` |
| En Suplidores el nombre de contacto aparece como nombre de empresa | `SUP-01`, `MIG-03` |
| El panel gasta la mitad del espacio en clima y frases | `RPT-01`, `RPT-04` |
| El selector de empresas muestra identificadores internos | `ARQ-13`, `EMP-05` |
| Hay dos empresas con nombre idéntico y sin diferenciador | `EMP-07`, `MIG-03` |
| Encabezados recortados: "Exit. Min", "Tax.%" | `PRO-04` |
| Español e inglés mezclados en la misma pantalla | `PR-05`, `RNF-05` |
| Formulario fijo arriba y tabla abajo en cada módulo | `PR-01` |
| Menú de acciones "⋮" sin etiquetas ni confirmación | `IU-01` |
| Casillas de selección sin acciones en lote | `PRO-10` |
| No hay clientes como entidad con estado de cuenta | `CLI-01`, `CLI-03` |
| No hay registro de quién hizo cada cambio | `ARQ-11` |

## Inventario de pantallas actuales

Sirve como lista de verificación de paridad: la primera versión de v2 no puede
dejar sin cubrir ninguna de estas pantallas.

| Pantalla actual | Módulo en v2 |
|---|---|
| Home / panel con métricas | `RPT-01` a `RPT-04` |
| Inventario · Suplidor | `SUP-01` a `SUP-06` |
| Inventario · Categorías | `CAT-01` a `CAT-05` |
| Inventario · Productos | `PRO-01` a `PRO-15`, `INV-01` a `INV-10` |
| Facturación | `FAC-01` a `FAC-18`, `CLI-01` a `CLI-05` |
| Hoja de Cuadre | `CAJ-01` a `CAJ-10` |
| Configurar Empresa | `EMP-01` a `EMP-08` |
| Modal de empresas / Crear Empresa | `EMP-05`, `ADM-01` |
| Barra superior: idioma, tema, usuario | `RNF-05`, `IU-03`, `AUT-05` |

## La consola administrativa ya existe

Además de la app de empresa hay una segunda aplicación, **AIOP**, con su propio
menú bajo la sección ADMINISTRATIVO: Usuarios, Empresas, Roles y Métodos de
Pago, y una identidad visual distinta (morado, frente al verde de la app de
empresa).

Esto es importante para el plan: la separación en dos aplicaciones que recomienda
[00-decision-arquitectura.md](00-decision-arquitectura.md) **ya está hecha**. No
hay que convencer a nadie ni rehacer el rumbo; hay que formalizar los límites
—sesiones separadas, permisos verificados, privacidad de datos— y cerrar los
huecos funcionales.

El inventario detallado de esas cuatro pantallas, con sus hallazgos, está en
[06-consola-plataforma.md](06-consola-plataforma.md). Los tres más urgentes:

- El panel muestra datos de demostración de la plantilla (facturas y clientes
  ficticios, "Goal: $100000") en lugar de métricas reales.
- Los cinco roles existen como nombres sin permisos asociados, y los nueve
  usuarios son todos "Administrador".
- Hay empresas duplicadas, una empresa sin usuarios y usuarios sin empresa.

## Dos países en los mismos datos

Los registros actuales mezclan direcciones y teléfonos de **Colombia** (Planeta
Rica, Córdoba, teléfonos que empiezan en 300 y 314) y de **Puerto Rico**
(Bayamón, teléfonos 787). Son regímenes fiscales y monedas distintos.

La primera versión de estos requisitos asumía solo Puerto Rico. El modelo se
ajusta para que país, moneda y régimen de impuesto sean configuración de cada
empresa (`ADM-22`, `EMP-02`), pero **el alcance real depende de una respuesta**:
si Colombia entra en serio, la facturación electrónica ante la DIAN es un módulo
completo, no un ajuste. Es la pregunta 1 de
[12-limites-y-pendientes.md](12-limites-y-pendientes.md).
