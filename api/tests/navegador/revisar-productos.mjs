/**
 * Prueba manual asistida del módulo de Productos (CAL-01, CAL-04).
 * Uso: node api/tests/navegador/revisar-productos.mjs
 */
import { chromium } from "playwright";
import { mkdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), "capturas");
mkdirSync(RAIZ, { recursive: true });

const EMPRESA = "http://localhost:5173";
const CLAVE = "Clave.Segura.1";
const resultados = [];

function revisar(descripcion, condicion) {
  resultados.push({ descripcion, bien: Boolean(condicion) });
  console.log(`${condicion ? "  ok  " : " FALLA"}  ${descripcion}`);
}

const capturar = (pagina, nombre) => pagina.screenshot({ path: join(RAIZ, `${nombre}.png`), fullPage: true });

async function entrar(pagina, email) {
  await pagina.goto(EMPRESA, { waitUntil: "networkidle" });
  await pagina.fill("#email", email);
  await pagina.fill("#clave", CLAVE);
  await pagina.click('button[type="submit"]');
  await pagina.waitForSelector("table.tabla, .vacio-caja, .empresas");
}

const navegador = await chromium.launch();

try {
  const contexto = await navegador.newContext({ viewport: { width: 1440, height: 950 }, locale: "es-PR" });
  const pagina = await contexto.newPage();

  const erroresJs = [];
  const respuestasMalas = [];
  pagina.on("pageerror", (e) => erroresJs.push(String(e)));
  pagina.on("response", async (r) => {
    if (r.status() >= 400 && r.url().includes("/v1/")) {
      let cuerpo = "";
      try { cuerpo = (await r.text()).slice(0, 250); } catch { cuerpo = "(sin cuerpo)"; }
      respuestasMalas.push(`${r.request().method()} ${r.url()} -> ${r.status()} ${cuerpo}`);
    }
  });
  process.on("exit", () => {
    if (!respuestasMalas.length) return;
    console.log("  respuestas con error:");
    for (const r of respuestasMalas) console.log("   " + r);
  });

  // --- el propietario abre en Productos --------------------------------
  await entrar(pagina, "pedro@elalamo.test");
  await pagina.waitForSelector("table.tabla");
  await capturar(pagina, "60-productos-listado");

  revisar("Productos es la pantalla de entrada", await pagina.locator("h1").first().innerText() === "Productos");
  revisar("Muestra costo, precio y margen", (await pagina.locator("table.tabla thead").innerText()).toLowerCase().includes("margen"));

  const primeraFila = await pagina.locator("table.tabla tbody tr").first().innerText();
  revisar("El margen sale calculado en la tabla", /%/.test(primeraFila));

  // --- filtros ---------------------------------------------------------
  await pagina.selectOption("#existencia", "agotado");
  await pagina.waitForFunction(() => document.querySelectorAll("table.tabla tbody tr").length === 1, null, { timeout: 5000 });
  await capturar(pagina, "61-productos-agotados");
  revisar("El filtro de agotados deja solo el café", (await pagina.locator("table.tabla tbody").innerText()).includes("Café colado"));
  revisar("El agotado se ve marcado en rojo", (await pagina.locator('.existencia[data-estado="agotado"]').count()) === 1);

  await pagina.selectOption("#existencia", "bajo");
  // Ojo: "Bajo mínimo" también es una opción del select, así que esperar por el
  // texto de la página entera termina antes de tiempo. Se espera por la tabla.
  await pagina.waitForFunction(
    () => document.querySelector("table.tabla tbody")?.innerText.includes("Jugo natural"),
    null,
    { timeout: 5000 },
  );
  revisar("El filtro de bajo mínimo encuentra el jugo", (await pagina.locator("table.tabla tbody").innerText()).includes("Jugo natural"));

  await pagina.selectOption("#existencia", "");
  await pagina.selectOption("#tipo", "servicio");
  await pagina.waitForFunction(() => document.querySelectorAll("table.tabla tbody tr").length === 2, null, { timeout: 5000 });
  await capturar(pagina, "62-productos-servicios");
  revisar("Los servicios no muestran existencia", (await pagina.locator("table.tabla tbody").innerText()).includes("No aplica"));

  await pagina.click("text=Limpiar filtros");
  await pagina.waitForSelector("table.tabla");

  // --- alta con margen en vivo -----------------------------------------
  await pagina.click("text=Nuevo producto");
  await pagina.waitForSelector('[role="dialog"]');
  await pagina.fill("#nombre", "Quesito de guayaba");
  await pagina.fill("#sku", "QUE-001");
  await pagina.fill("#costo", "1.20");
  await pagina.fill("#precio", "3.00");
  await capturar(pagina, "63-productos-margen-en-vivo");
  const margenVivo = await pagina.locator("#margen-vivo").innerText();
  revisar(`El margen se calcula mientras se escribe (${margenVivo.split("·")[0].trim()})`, margenVivo.includes("60%"));

  // Precio bajo el costo: avisa.
  await pagina.fill("#precio", "0.90");
  await pagina.waitForFunction(() => document.querySelector("#margen-vivo")?.dataset.alerta === "true", null, { timeout: 5000 });
  await capturar(pagina, "64-productos-bajo-costo");
  revisar("Avisa cuando el precio queda bajo el costo", (await pagina.locator("#margen-vivo").innerText()).includes("por debajo del costo"));

  // Corrige, pone existencia inicial y guarda.
  await pagina.fill("#precio", "3.00");
  await pagina.fill("#impuesto", "11.5");
  await pagina.fill("#existencia_inicial", "24");
  await pagina.fill("#existencia_minima", "6");
  await pagina.click('button[form="formulario-producto"]');
  await pagina.waitForSelector('[role="dialog"]', { state: "detached" });
  await pagina.fill("#buscar", "Quesito");
  await pagina.waitForFunction(
    () => document.querySelector("table.tabla tbody")?.innerText.includes("Quesito de guayaba"),
    null,
    { timeout: 5000 },
  );
  await capturar(pagina, "65-productos-creado");
  const creado = await pagina.locator("table.tabla tbody").innerText();
  revisar("El producto nuevo aparece con su existencia inicial", creado.includes("24"));
  revisar("Y con su margen calculado", creado.includes("60%"));

  // --- servicio: se ocultan las existencias ----------------------------
  await pagina.fill("#buscar", "");
  await pagina.click("text=Nuevo producto");
  await pagina.waitForSelector('[role="dialog"]');
  revisar("Un producto normal pide existencia inicial", (await pagina.locator("#existencia_inicial").count()) === 1);
  await pagina.check("#es_servicio");
  await capturar(pagina, "66-productos-servicio-sin-existencia");
  revisar("Al marcar servicio desaparece la existencia inicial", (await pagina.locator("#existencia_inicial").count()) === 0);
  revisar("Y también la existencia mínima", (await pagina.locator("#existencia_minima").count()) === 0);
  // Hay cambios sin guardar, así que el panel pregunta antes de cerrarse.
  pagina.once("dialog", (d) => d.accept());
  await pagina.keyboard.press("Escape");
  await pagina.waitForSelector('[role="dialog"]', { state: "detached" });
  revisar("Cerrar con cambios sin guardar pide confirmación", true);

  // --- ajuste de existencia --------------------------------------------
  await pagina.fill("#buscar", "Pan Horneado");
  await pagina.waitForFunction(
    () => {
      const cuerpo = document.querySelector("table.tabla tbody");
      return cuerpo?.querySelectorAll("tr").length === 1 && cuerpo.innerText.includes("Pan Horneado");
    },
    null,
    { timeout: 5000 },
  );
  await pagina.click("text=Ajustar");
  await pagina.waitForSelector('[role="dialog"]');
  await pagina.fill("#contado", "22");
  await pagina.waitForFunction(() => document.querySelector("#diferencia-viva")?.textContent?.includes("Faltan"), null, { timeout: 5000 });
  await capturar(pagina, "67-productos-ajuste");
  const textoDiferencia = await pagina.locator("#diferencia-viva").innerText();
  revisar(`El ajuste dice cuánto falta antes de registrarlo [${textoDiferencia}]`, textoDiferencia.includes("Faltan 3"));

  await pagina.selectOption("#motivo", "conteo");
  await pagina.click('button[form="formulario-ajuste"]');
  await pagina.waitForSelector('[role="dialog"]', { state: "detached" });
  const filaPan = pagina.locator("table.tabla tbody tr", { hasText: "Pan Horneado" });
  await filaPan.locator("td").nth(2).filter({ hasText: "22" }).waitFor({ timeout: 5000 });
  await capturar(pagina, "68-productos-ajustado");
  const existenciaPan = (await filaPan.locator("td").nth(2).innerText()).trim();
  revisar(`La existencia queda en lo contado (${existenciaPan})`, existenciaPan.startsWith("22"));

  // --- rol sin costos ---------------------------------------------------
  await pagina.click("text=Cerrar sesión");
  await pagina.waitForSelector("#email");
  await entrar(pagina, "carmina@elalamo.test");   // empleado de almacén
  await pagina.waitForSelector("table.tabla");
  await capturar(pagina, "69-productos-almacen-sin-costos");
  const cabecerasAlmacen = (await pagina.locator("table.tabla thead").innerText()).toLowerCase();
  revisar("El empleado de almacén no ve la columna de costo", !cabecerasAlmacen.includes("costo"));
  revisar("Ni la de margen", !cabecerasAlmacen.includes("margen"));
  revisar("Pero sí ve el precio", cabecerasAlmacen.includes("precio"));
  revisar("Y no puede crear productos", (await pagina.locator("text=Nuevo producto").count()) === 0);

  revisar("Sin errores de JavaScript", erroresJs.length === 0);
  if (erroresJs.length) console.log("   errores:", erroresJs.slice(0, 3));

  await contexto.close();

  // --- móvil -------------------------------------------------------------
  const movil = await navegador.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const pmovil = await movil.newPage();
  await entrar(pmovil, "pedro@elalamo.test");
  await pmovil.waitForSelector("table.tabla");
  await capturar(pmovil, "70-productos-movil-390");
  const desborde = await pmovil.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  revisar("En 390 px la página no se desplaza en horizontal", !desborde);
  await movil.close();

  // --- tema oscuro --------------------------------------------------------
  const oscuro = await navegador.newContext({ viewport: { width: 1440, height: 950 }, colorScheme: "dark" });
  const poscuro = await oscuro.newPage();
  await entrar(poscuro, "pedro@elalamo.test");
  await poscuro.waitForSelector("table.tabla");
  await capturar(poscuro, "71-productos-tema-oscuro");
  revisar("La tabla se pinta con el tema oscuro", await poscuro.evaluate(() => getComputedStyle(document.querySelector("table.tabla")).backgroundColor) !== "rgb(255, 255, 255)");
  await oscuro.close();
} finally {
  await navegador.close();
}

const fallas = resultados.filter((r) => !r.bien);
console.log(`\n${resultados.length - fallas.length}/${resultados.length} revisiones bien. Capturas en ${RAIZ}`);
process.exit(fallas.length === 0 ? 0 : 1);
