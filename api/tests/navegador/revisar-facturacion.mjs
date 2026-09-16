/**
 * Prueba manual asistida de la venta rápida (CAL-01, CAL-04).
 *
 * Esto recorre el panel lateral: el cliente está en el mostrador y la venta se
 * cierra en segundos. La factura como documento —borradores, vista previa,
 * cobro y anulación— se revisa en `revisar-hoja-factura.mjs`.
 *
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

/** La existencia que muestra el catálogo para un producto. */
async function existenciaDe(pagina, texto) {
  await irA(pagina, "Productos");
  await pagina.fill("#buscar", texto);
  await pagina.waitForFunction(
    (buscado) => {
      const c = document.querySelector("table.tabla tbody");
      return c?.querySelectorAll("tr").length === 1 && c.innerText.includes(buscado);
    },
    texto,
    { timeout: 5000 },
  );
  return parseFloat((await pagina.locator("table.tabla tbody tr td").nth(2).innerText()).trim());
}

try {
  const contexto = await navegador.newContext({ viewport: { width: 1440, height: 980 }, locale: "es-PR" });
  const pagina = await contexto.newPage();
  const { erroresJs } = vigilar(pagina);

  await entrar(pagina, "pedro@elalamo.test");

  // Cuánto pan hay antes de vender: las revisiones van contra esto y no contra
  // un número fijo, para que no dependan de cuántas veces se haya sembrado.
  const panAntes = await existenciaDe(pagina, "Pan de Leche");

  await irA(pagina, "Facturas");
  await capturar(pagina, "110-facturas-listado");

  // --- armar la venta ---------------------------------------------------
  await pagina.getByRole("button", { name: "Venta rápida" }).click();
  await pagina.waitForSelector('[role="dialog"]');

  await elegirProducto(pagina, 0, "Pan de Leche relleno de chocolate");
  await pagina.fill("#cantidad-0", "2");
  await pagina.waitForSelector("#totales-venta");
  await capturar(pagina, "111-venta-totales-en-vivo");

  let totales = await pagina.locator("#totales-venta").innerText();
  revisar("Calcula el subtotal mientras se escribe", totales.includes("19.00"));
  revisar("Desglosa el impuesto estatal y municipal", totales.includes("Estatal") && totales.includes("Municipal"));
  revisar("Y muestra el total", totales.includes("21.19"));

  // Un segundo renglón, para ver que suma.
  await pagina.getByRole("button", { name: "Agregar renglón" }).click();
  await elegirProducto(pagina, 1, "Café colado 12 oz");
  await pagina.fill("#cantidad-1", "1");
  await pagina.waitForFunction(
    () => document.querySelector("#totales-venta")?.textContent?.includes("21.50"),
    null,
    { timeout: 5000 },
  );
  totales = await pagina.locator("#totales-venta").innerText();
  revisar("Un renglón más cambia los totales", totales.includes("21.50"));   // 19.00 + 2.50

  // --- el café está agotado: la caja decide -----------------------------
  await pagina.uncheck("#cobrar_ahora");
  await pagina.getByRole("button", { name: /Emitir por/ }).click();
  await pagina.waitForSelector("#aviso-faltantes");
  await capturar(pagina, "112-venta-sin-existencia");
  const faltantes = await pagina.locator("#aviso-faltantes").innerText();
  revisar("Avisa que no hay existencia y dice de qué", faltantes.includes("Café colado"));
  revisar("Y ofrece vender igual", faltantes.includes("Vender igual"));

  // Se quita el café y se factura solo el pan, cobrando en efectivo.
  await pagina.click(".renglon:nth-of-type(2) .renglon__quitar");
  await pagina.check("#cobrar_ahora");
  await pagina.fill("#recibido", "25.00");
  await pagina.waitForFunction(
    () => document.querySelector("#totales-venta")?.textContent?.includes("21.19"),
    null,
    { timeout: 5000 },
  );
  await pagina.waitForSelector("#cambio-vivo");
  revisar("Calcula el cambio antes de cobrar", (await pagina.locator("#cambio-vivo").innerText()).includes("3.81"));

  // --- emitir abre la factura hecha ---------------------------------------
  await pagina.getByRole("button", { name: /Emitir por/ }).click();
  await pagina.waitForSelector(".hoja");
  await capturar(pagina, "113-venta-emitida");

  const folio = await pagina.locator("#hoja-folio").innerText();
  revisar(`Al emitir se abre la factura con su folio (${folio})`, /^F-\d{5}$/.test(folio));
  revisar(
    "Pagada al contado, sin saldo",
    !(await pagina.locator("#totales-hoja").innerText()).includes("Saldo"),
  );
  revisar("El aviso confirma la emisión", (await pagina.locator(".avisos__nota").last().innerText()).includes(folio));

  await pagina.getByRole("button", { name: "Cerrar", exact: true }).click();
  await pagina.waitForSelector("table.tabla");

  // --- el inventario bajó ------------------------------------------------
  const panDespues = await existenciaDe(pagina, "Pan de Leche");
  revisar(`Vender descontó el inventario (${panAntes} → ${panDespues})`, panDespues === panAntes - 2);

  // --- una venta a crédito queda con saldo --------------------------------
  await irA(pagina, "Facturas");
  await pagina.getByRole("button", { name: "Venta rápida" }).click();
  await pagina.waitForSelector('[role="dialog"]');
  await elegirProducto(pagina, 0, "Torta chocolate media libra");
  await pagina.fill("#cantidad-0", "1");
  await pagina.uncheck("#cobrar_ahora");
  await pagina.waitForSelector("#totales-venta");
  await pagina.getByRole("button", { name: /Emitir por/ }).click();
  await pagina.waitForSelector(".hoja");
  revisar("Sin cobrar queda con saldo", (await pagina.locator("#totales-hoja").innerText()).includes("Saldo"));
  revisar(
    "Y la propia hoja ofrece cobrarla",
    (await pagina.locator(".hoja-aside").innerText()).toLowerCase().includes("cobrar"),
  );

  await pagina.getByRole("button", { name: "Cerrar", exact: true }).click();
  await pagina.waitForSelector("table.tabla");

  // --- resumen del listado ------------------------------------------------
  await capturar(pagina, "116-facturas-listado");
  const resumen = await pagina.locator(".resumen").innerText();
  revisar("El listado resume lo facturado", resumen.includes("Facturas") && resumen.includes("Por cobrar"));
  revisar("Y las facturas aparecen con su estado", (await pagina.locator(".etiqueta").first().innerText()).length > 0);

  // --- roles ---------------------------------------------------------------
  await salir(pagina);
  await entrar(pagina, "emily@elalamo.test");   // mostrador
  revisar("El de mostrador tiene Facturas", (await pagina.locator('.rail__item:has-text("Facturas")').count()) === 1);
  await irA(pagina, "Facturas");
  await pagina.locator("table.tabla tbody tr").first().getByRole("button", { name: "Ver" }).click();
  await pagina.waitForSelector(".hoja");
  revisar(
    "Pero no puede anular",
    (await pagina.getByRole("button", { name: "Anular esta factura" }).count()) === 0,
  );

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
  let desborde = await pm.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  revisar("En 390 px el listado no se desplaza en horizontal", !desborde);

  // La hoja es lo más ancho de la aplicación: si algo se sale, es aquí.
  await pm.getByRole("button", { name: "Nueva factura" }).click();
  await pm.waitForSelector(".hoja");
  await capturar(pm, "118-hoja-movil-390");
  desborde = await pm.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  revisar("Y la hoja de factura tampoco", !desborde);

  await movil.close();
} finally {
  await navegador.close();
}

process.exit(cerrar() === 0 ? 0 : 1);
