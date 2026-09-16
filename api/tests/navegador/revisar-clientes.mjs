/**
 * Prueba manual asistida del módulo de Clientes (CAL-01, CAL-04).
 * Uso: node api/tests/navegador/revisar-clientes.mjs
 */
import { chromium } from "playwright";
import { capturar, crearRevisor, entrar, irA, salir, vigilar } from "./_ayudas.mjs";

const { revisar, cerrar } = crearRevisor();
const navegador = await chromium.launch();

try {
  const contexto = await navegador.newContext({ viewport: { width: 1440, height: 950 }, locale: "es-PR" });
  const pagina = await contexto.newPage();
  const { erroresJs } = vigilar(pagina);

  await entrar(pagina, "pedro@elalamo.test");
  await irA(pagina, "Clientes");
  await capturar(pagina, "100-clientes-listado");

  revisar("Clientes está en el menú, bajo Ventas", (await pagina.locator('.rail__titulo:has-text("Ventas")').count()) === 1);
  revisar("Lista los cinco clientes activos", (await pagina.locator("table.tabla tbody tr").count()) === 5);
  revisar("El exento se ve marcado", (await pagina.locator('.etiqueta:has-text("Exento")').count()) === 1);
  revisar("Quien no tiene crédito lo dice", (await pagina.locator("table.tabla tbody").innerText()).includes("Sin crédito"));

  // --- búsqueda por teléfono con otro formato ---------------------------
  await pagina.fill("#buscar", "(787) 555-4040");
  // La lista sin filtrar ya contiene a María, así que esperar solo por su
  // nombre se cumple antes de tiempo: hay que esperar por la fila única.
  await pagina.waitForFunction(
    () => {
      const cuerpo = document.querySelector("table.tabla tbody");
      return cuerpo?.querySelectorAll("tr").length === 1 && cuerpo.innerText.includes("María Fernández");
    },
    null,
    { timeout: 5000 },
  );
  await capturar(pagina, "101-clientes-busqueda-telefono");
  revisar("Encuentra por teléfono aunque esté escrito distinto", (await pagina.locator("table.tabla tbody tr").count()) === 1);

  await pagina.click("text=Limpiar filtros");
  await pagina.waitForSelector("table.tabla");

  // --- filtro de crédito -------------------------------------------------
  await pagina.check("#con_credito");
  await pagina.waitForFunction(
    () => document.querySelectorAll("table.tabla tbody tr").length === 3,
    null,
    { timeout: 5000 },
  );
  revisar("El filtro de crédito deja los tres que lo tienen", (await pagina.locator("table.tabla tbody tr").count()) === 3);
  await pagina.uncheck("#con_credito");
  await pagina.waitForSelector("table.tabla");

  // --- alta: la exención pide su certificado ----------------------------
  await pagina.click("text=Nuevo cliente");
  await pagina.waitForSelector('[role="dialog"]');
  revisar("Sin marcar exento no se pide el certificado", (await pagina.locator("#certificado_exencion").count()) === 0);

  await pagina.fill("#nombre", "Escuela Superior Bayamón");
  await pagina.selectOption("#tipo_campo", "empresa");
  await pagina.check("#exento");
  await capturar(pagina, "102-clientes-exento");
  revisar("Al marcar exento aparece el certificado", (await pagina.locator("#certificado_exencion").count()) === 1);

  // Se intenta guardar sin el número: el servidor lo rechaza.
  await pagina.click('button[form="formulario-cliente"]');
  await pagina.waitForSelector("#certificado_exencion-error");
  await capturar(pagina, "103-clientes-exento-sin-certificado");
  revisar(
    "Sin número de certificado no deja guardar",
    (await pagina.locator("#certificado_exencion-error").innerText()).includes("número de su certificado"),
  );
  revisar("Y no pierde lo que ya estaba escrito", (await pagina.inputValue("#nombre")) === "Escuela Superior Bayamón");

  // Se completa y se guarda.
  await pagina.fill("#certificado_exencion", "EXE-2026-77");
  await pagina.fill("#telefono", "787-555-1212");
  await pagina.fill("#limite_credito", "800.00");
  revisar(
    "Explica qué significa poner un límite",
    (await pagina.locator("#ayuda-credito").innerText()).includes("a crédito hasta ese monto"),
  );

  await pagina.click('button[form="formulario-cliente"]');
  await pagina.waitForSelector('[role="dialog"]', { state: "detached" });
  await pagina.fill("#buscar", "Escuela Superior");
  await pagina.waitForFunction(
    () => document.querySelector("table.tabla tbody")?.innerText.includes("Escuela Superior"),
    null,
    { timeout: 5000 },
  );
  await capturar(pagina, "104-clientes-creado");

  const fila = await pagina.locator("table.tabla tbody tr", { hasText: "Escuela Superior" }).innerText();
  revisar("El cliente nuevo aparece con su crédito", fila.includes("800.00"));
  revisar("Y marcado como exento", fila.includes("EXENTO") || fila.includes("Exento"));
  revisar("El aviso confirma que se guardó", (await pagina.locator(".avisos__nota").innerText()).includes("quedó guardado"));

  // --- quitar la exención se lleva el certificado ------------------------
  await pagina.locator("table.tabla tbody tr", { hasText: "Escuela Superior" }).locator("text=Editar").click();
  await pagina.waitForSelector('[role="dialog"]');
  await pagina.uncheck("#exento");
  revisar("Al quitar la exención desaparece el campo del certificado", (await pagina.locator("#certificado_exencion").count()) === 0);
  await pagina.click('button[form="formulario-cliente"]');
  await pagina.waitForSelector('[role="dialog"]', { state: "detached" });
  await pagina.waitForFunction(
    () => !(document.querySelector("table.tabla tbody")?.innerText ?? "").includes("Exento"),
    null,
    { timeout: 5000 },
  );
  revisar("Y deja de verse como exento en la tabla", true);

  // --- roles --------------------------------------------------------------
  await salir(pagina);
  await entrar(pagina, "carmina@elalamo.test");   // empleado de almacén
  await capturar(pagina, "105-clientes-almacen-sin-modulo");
  revisar("Al de almacén no se le ofrece Clientes", (await pagina.locator('.rail__item:has-text("Clientes")').count()) === 0);

  await salir(pagina);
  await entrar(pagina, "emily@elalamo.test");     // empleado de mostrador
  revisar("Al de mostrador sí, porque lo necesita para facturar", (await pagina.locator('.rail__item:has-text("Clientes")').count()) === 1);
  revisar("Y no se le ofrece el catálogo", (await pagina.locator('.rail__item:has-text("Productos")').count()) === 0);
  await irA(pagina, "Clientes");
  revisar("Puede crear clientes al vuelo", (await pagina.locator("text=Nuevo cliente").count()) === 1);

  await salir(pagina);
  await entrar(pagina, "sonia@elalamo.test");     // contador
  await irA(pagina, "Clientes");
  await capturar(pagina, "106-clientes-contador");
  revisar("El contador ve la lista", (await pagina.locator("table.tabla tbody tr").count()) > 0);
  revisar("Pero no puede crear", (await pagina.locator("text=Nuevo cliente").count()) === 0);

  revisar("Sin errores de JavaScript", erroresJs.length === 0);
  if (erroresJs.length) console.log("   errores:", erroresJs.slice(0, 3));

  await contexto.close();

  // --- móvil ---------------------------------------------------------------
  const movil = await navegador.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const pm = await movil.newPage();
  await entrar(pm, "pedro@elalamo.test");
  await irA(pm, "Clientes");
  await capturar(pm, "107-clientes-movil-390");
  const desborde = await pm.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  revisar("En 390 px la página no se desplaza en horizontal", !desborde);
  await movil.close();
} finally {
  await navegador.close();
}

process.exit(cerrar() === 0 ? 0 : 1);
