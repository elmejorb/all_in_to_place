/**
 * Prueba manual asistida de Facturación (CAL-01, CAL-04).
 * Uso: node api/tests/navegador/revisar-facturacion.mjs
 */
import { chromium } from "playwright";
import { capturar, crearRevisor, entrar, irA, salir, vigilar } from "./_ayudas.mjs";

const { revisar, cerrar } = crearRevisor();
const navegador = await chromium.launch();

/** Elige un producto en el renglón indicado, por su texto visible. */
async function elegirProducto(pagina, clave, nombre) {
  await pagina.selectOption(`#producto-${clave}`, { label: nombre });
}

try {
  const contexto = await navegador.newContext({ viewport: { width: 1440, height: 980 }, locale: "es-PR" });
  const pagina = await contexto.newPage();
  const { erroresJs } = vigilar(pagina);

  await entrar(pagina, "pedro@elalamo.test");
  await irA(pagina, "Facturas");
  await capturar(pagina, "110-facturas-vacio");
  revisar("Facturas abre con su estado vacío", (await pagina.locator(".vacio-caja").innerText()).includes("Aún no has facturado"));

  // --- armar la venta ---------------------------------------------------
  await pagina.getByRole("button", { name: "Emitir la primera" }).click();
  await pagina.waitForSelector('[role="dialog"]');

  await elegirProducto(pagina, 0, "Pan de Leche relleno de chocolate");
  await pagina.fill("#cantidad-0", "2");
  await pagina.waitForSelector("#totales-venta");
  await capturar(pagina, "111-factura-totales-en-vivo");

  let totales = await pagina.locator("#totales-venta").innerText();
  revisar("Calcula el subtotal mientras se escribe", totales.includes("19.00"));
  revisar("Desglosa el impuesto estatal y municipal", totales.includes("Estatal") && totales.includes("Municipal"));
  revisar("Y muestra el total", totales.includes("21.19"));

  // Un segundo renglón, para ver que suma.
  await pagina.getByRole("button", { name: "Agregar renglón" }).click();
  await elegirProducto(pagina, 1, "Café colado 12 oz");
  await pagina.fill("#cantidad-1", "1");
  await pagina.waitForFunction(
    () => !document.querySelector("#totales-venta")?.textContent?.includes("21.19"),
    null,
    { timeout: 5000 },
  );
  totales = await pagina.locator("#totales-venta").innerText();
  revisar("Un renglón más cambia los totales", totales.includes("21.50"));   // 19.00 + 2.50

  // --- el café está agotado: la caja decide -----------------------------
  await pagina.uncheck("#cobrar_ahora");
  await pagina.getByRole("button", { name: /Emitir por/ }).click();
  await pagina.waitForSelector("#aviso-faltantes");
  await capturar(pagina, "112-factura-sin-existencia");
  const faltantes = await pagina.locator("#aviso-faltantes").innerText();
  revisar("Avisa que no hay existencia y dice de qué", faltantes.includes("Café colado"));
  revisar("Y ofrece vender igual", faltantes.includes("Vender igual"));

  // Se quita el café y se factura solo el pan, cobrando en efectivo.
  await pagina.click(".renglon:nth-of-type(2) .renglon__quitar");
  await pagina.check("#cobrar_ahora");
  await pagina.fill("#recibido", "25.00");
  // El total vuelve a 21.19 tras quitar el café; hay que esperar a que el
  // cálculo se refresque antes de mirar el cambio.
  await pagina.waitForFunction(
    () => document.querySelector("#totales-venta")?.textContent?.includes("21.19"),
    null,
    { timeout: 5000 },
  );
  await pagina.waitForSelector("#cambio-vivo");
  revisar("Calcula el cambio antes de cobrar", (await pagina.locator("#cambio-vivo").innerText()).includes("3.81"));

  await pagina.getByRole("button", { name: /Emitir por/ }).click();
  await pagina.waitForSelector("#totales-factura");
  await capturar(pagina, "113-factura-emitida");

  const detalle = await pagina.locator('[role="dialog"]').innerText();
  revisar("La factura queda emitida con su folio", detalle.includes("F-00001"));
  revisar("Y pagada, sin saldo", !detalle.includes("Saldo"));
  revisar("El aviso confirma la emisión", (await pagina.locator(".avisos__nota").innerText()).includes("F-00001"));

  await pagina.click(".panel-lateral__cerrar");
  await pagina.waitForSelector("table.tabla");

  // --- el inventario bajó ------------------------------------------------
  await irA(pagina, "Productos");
  await pagina.fill("#buscar", "Pan de Leche");
  await pagina.waitForFunction(
    () => {
      const c = document.querySelector("table.tabla tbody");
      return c?.querySelectorAll("tr").length === 1 && c.innerText.includes("Pan de Leche");
    },
    null,
    { timeout: 5000 },
  );
  const existencia = (await pagina.locator("table.tabla tbody tr td").nth(2).innerText()).trim();
  revisar(`Vender descontó el inventario (${existencia})`, existencia.startsWith("113"));   // 115 - 2

  // --- cobrar una factura pendiente --------------------------------------
  await irA(pagina, "Facturas");
  await pagina.getByRole("button", { name: "Nueva factura" }).click();
  await pagina.waitForSelector('[role="dialog"]');
  await elegirProducto(pagina, 0, "Torta chocolate media libra");
  await pagina.fill("#cantidad-0", "1");
  await pagina.uncheck("#cobrar_ahora");
  await pagina.waitForSelector("#totales-venta");
  await pagina.getByRole("button", { name: /Emitir por/ }).click();
  await pagina.waitForSelector("#totales-factura");
  revisar("Sin cobrar queda con saldo", (await pagina.locator('[role="dialog"]').innerText()).includes("Saldo"));

  await pagina.getByRole("button", { name: "Cobrar", exact: true }).click();
  await pagina.waitForSelector("#monto");
  await pagina.selectOption("#metodo", "ath_movil");
  await pagina.getByRole("button", { name: "Registrar cobro" }).click();
  await pagina.waitForFunction(
    () => !document.querySelector('[role="dialog"]')?.textContent?.includes("Saldo"),
    null,
    { timeout: 5000 },
  );
  await capturar(pagina, "114-factura-cobrada");
  revisar("Al cobrar el saldo desaparece", !(await pagina.locator('[role="dialog"]').innerText()).includes("Saldo"));

  // --- anular devuelve la mercancía --------------------------------------
  pagina.once("dialog", (d) => d.accept("Prueba de anulación"));
  await pagina.getByRole("button", { name: "Anular" }).click();
  await pagina.waitForFunction(
    () => document.querySelector('[role="dialog"]')?.textContent?.includes("Anulada:"),
    null,
    { timeout: 5000 },
  );
  await capturar(pagina, "115-factura-anulada");
  revisar("La anulación queda con su motivo a la vista", (await pagina.locator('[role="dialog"]').innerText()).includes("Prueba de anulación"));

  await pagina.click(".panel-lateral__cerrar");
  await irA(pagina, "Productos");
  await pagina.fill("#buscar", "Torta");
  await pagina.waitForFunction(
    () => {
      const c = document.querySelector("table.tabla tbody");
      return c?.querySelectorAll("tr").length === 1 && c.innerText.includes("Torta");
    },
    null,
    { timeout: 5000 },
  );
  const torta = (await pagina.locator("table.tabla tbody tr td").nth(2).innerText()).trim();
  revisar(`Anular devolvió la mercancía (${torta})`, torta.startsWith("10"));

  // --- resumen del listado ------------------------------------------------
  await irA(pagina, "Facturas");
  await capturar(pagina, "116-facturas-listado");
  const resumen = await pagina.locator(".resumen").innerText();
  revisar("El listado resume lo facturado", resumen.includes("Facturas") && resumen.includes("Por cobrar"));
  revisar("Y las facturas aparecen con su estado", (await pagina.locator(".etiqueta").first().innerText()).length > 0);

  // --- roles ---------------------------------------------------------------
  await salir(pagina);
  await entrar(pagina, "emily@elalamo.test");   // mostrador
  revisar("El de mostrador tiene Facturas", (await pagina.locator('.rail__item:has-text("Facturas")').count()) === 1);
  await irA(pagina, "Facturas");
  await pagina.locator("table.tabla tbody tr").first().locator("text=Ver").click();
  await pagina.waitForSelector("#totales-factura");
  revisar("Pero no puede anular", (await pagina.getByRole("button", { name: "Anular" }).count()) === 0);

  await salir(pagina);
  await entrar(pagina, "carmina@elalamo.test");   // almacén
  revisar("Al de almacén no se le ofrece Facturas", (await pagina.locator('.rail__item:has-text("Facturas")').count()) === 0);

  revisar("Sin errores de JavaScript", erroresJs.length === 0);
  if (erroresJs.length) console.log("   errores:", erroresJs.slice(0, 3));

  await contexto.close();

  // --- móvil ---------------------------------------------------------------
  const movil = await navegador.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const pm = await movil.newPage();
  await entrar(pm, "pedro@elalamo.test");
  await irA(pm, "Facturas");
  await capturar(pm, "117-facturas-movil-390");
  const desborde = await pm.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  revisar("En 390 px la página no se desplaza en horizontal", !desborde);
  await movil.close();
} finally {
  await navegador.close();
}

process.exit(cerrar() === 0 ? 0 : 1);
