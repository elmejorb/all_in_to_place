/**
 * Prueba manual asistida del módulo de Categorías (CAL-01, CAL-04).
 * Uso: node api/tests/navegador/revisar-categorias.mjs
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
}

/** Contraste WCAG entre dos colores calculados. */
function contraste(rgb1, rgb2) {
  const lum = (rgb) => {
    const [r, g, b] = rgb.match(/\d+/g).slice(0, 3).map(Number).map((v) => {
      const c = v / 255;
      return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const a = lum(rgb1);
  const b = lum(rgb2);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

const navegador = await chromium.launch();

try {
  const contexto = await navegador.newContext({ viewport: { width: 1280, height: 900 }, locale: "es-PR" });
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

  await entrar(pagina, "pedro@elalamo.test");
  await pagina.click('nav.nav button:has-text("Categorías")');
  await pagina.waitForSelector("table.tabla");
  await capturar(pagina, "40-categorias-listado");

  revisar("Muestra las cuatro categorías de la panadería", (await pagina.locator("table.tabla tbody tr").count()) === 4);
  revisar("Cada categoría lleva su etiqueta de color", (await pagina.locator(".etiqueta-color").count()) === 4);

  // --- nombre repetido, incluso con otras mayúsculas -------------------
  await pagina.click("text=Nueva categoría");
  await pagina.waitForSelector('[role="dialog"]');
  await pagina.fill("#nombre", "dULCES");
  await pagina.click('button[form="formulario-categoria"]');
  await pagina.waitForSelector("#nombre-error");
  await capturar(pagina, "41-categorias-nombre-repetido");
  revisar("Detecta el nombre repetido sin importar mayúsculas", (await pagina.locator("#nombre-error").innerText()).includes("Ya tienes una categoría"));

  // El mensaje tiene que irse en cuanto se corrige el campo.
  await pagina.fill("#nombre", "Congelados");
  revisar("El error desaparece al corregir el nombre", (await pagina.locator("#nombre-error").count()) === 0);

  // --- crear con color -------------------------------------------------
  await pagina.fill("#descripcion", "Productos que van al freezer");
  await pagina.click('.color[data-color="azul"] span');
  await capturar(pagina, "42-categorias-panel-color");
  revisar("El color elegido queda marcado", (await pagina.locator('.color[data-color="azul"]').getAttribute("data-elegido")) === "true");

  await pagina.click('button[form="formulario-categoria"]');
  await pagina.waitForSelector('[role="dialog"]', { state: "detached" });
  await pagina.waitForFunction(() => document.body.innerText.includes("Congelados"), null, { timeout: 5000 });
  await capturar(pagina, "43-categorias-creada");
  revisar("La categoría nueva aparece con su color", (await pagina.locator('.etiqueta-color[data-color="azul"]').count()) === 1);

  // --- editar ----------------------------------------------------------
  const fila = pagina.locator("table.tabla tbody tr", { hasText: "Congelados" });
  await fila.locator("text=Editar").click();
  await pagina.waitForSelector('[role="dialog"]');
  revisar("El panel de edición llega con los datos", (await pagina.inputValue("#descripcion")) === "Productos que van al freezer");
  await pagina.fill("#nombre", "Congelados y hielo");
  await pagina.click('button[form="formulario-categoria"]');
  await pagina.waitForSelector('[role="dialog"]', { state: "detached" });
  await pagina.waitForFunction(() => document.body.innerText.includes("Congelados y hielo"), null, { timeout: 5000 });
  revisar("La edición se refleja en la tabla", (await pagina.locator("table.tabla tbody").innerText()).includes("Congelados y hielo"));

  // --- conteo de productos y borrado con reasignación (CAT-02, CAT-03) ---
  await pagina.fill("#buscar", "");
  await pagina.waitForFunction(
    () => (document.querySelector("table.tabla tbody")?.innerText ?? "").includes("Pastelería"),
    null,
    { timeout: 5000 },
  );
  const filaPasteleria = pagina.locator("table.tabla tbody tr", { hasText: "Pastelería" });
  const conteo = (await filaPasteleria.locator("td").nth(2).innerText()).trim();
  // Cuenta también los inactivos: si no, borrar la categoría dejaría huérfano
  // al producto descontinuado (CAT-02 junto con CAT-03).
  revisar(`La tabla dice cuántos productos tiene cada categoría (Pastelería: ${conteo})`, conteo === "5");

  // Con productos dentro, pide a dónde pasarlos antes de borrar.
  let preguntoDestino = false;
  pagina.once("dialog", async (d) => {
    preguntoDestino = d.type() === "prompt" && d.message().includes("¿A cuál categoría los pasamos?");
    await d.dismiss();
  });
  await filaPasteleria.locator("text=Eliminar").click();
  await pagina.waitForTimeout(400);
  revisar("Para borrar una categoría con productos, pregunta a dónde van", preguntoDestino);

  // Una categoría vacía se borra sin más trámite.
  const filaCongelados = pagina.locator("table.tabla tbody tr", { hasText: "Congelados y hielo" });
  pagina.once("dialog", (d) => d.accept());
  await filaCongelados.locator("text=Eliminar").click();
  await pagina.waitForFunction(
    () => !(document.querySelector("table.tabla tbody")?.innerText ?? "").includes("Congelados y hielo"),
    null,
    { timeout: 5000 },
  );
  await capturar(pagina, "46-categorias-tras-borrar");
  revisar("Una categoría sin productos se borra directo", true);

  // --- contraste de las etiquetas en tema claro -------------------------
  const contrastes = await pagina.$$eval(".etiqueta-color", (nodos) =>
    nodos.map((n) => {
      const e = getComputedStyle(n);
      return [n.dataset.color, e.color, e.backgroundColor];
    }),
  );
  const flojas = contrastes.filter(([, texto, fondo]) => contraste(texto, fondo) < 4.5);
  revisar(`Todas las etiquetas pasan contraste AA en tema claro (${contrastes.length} revisadas)`, flojas.length === 0);
  if (flojas.length) console.log("   flojas:", flojas.map((f) => f[0]).join(", "));

  revisar("Sin errores de JavaScript", erroresJs.length === 0);
  if (erroresJs.length) console.log("   errores:", erroresJs.slice(0, 3));

  await contexto.close();

  // --- tema oscuro ------------------------------------------------------
  const oscuro = await navegador.newContext({ viewport: { width: 1280, height: 900 }, colorScheme: "dark" });
  const poscuro = await oscuro.newPage();
  await entrar(poscuro, "pedro@elalamo.test");
  await poscuro.click('nav.nav button:has-text("Categorías")');
  await poscuro.waitForSelector(".etiqueta-color");
  await capturar(poscuro, "44-categorias-tema-oscuro");

  const contrastesOscuro = await poscuro.$$eval(".etiqueta-color", (nodos) =>
    nodos.map((n) => {
      const e = getComputedStyle(n);
      return [n.dataset.color, e.color, e.backgroundColor];
    }),
  );
  const flojasOscuro = contrastesOscuro.filter(([, texto, fondo]) => contraste(texto, fondo) < 4.5);
  revisar(`Todas las etiquetas pasan contraste AA en tema oscuro (${contrastesOscuro.length} revisadas)`, flojasOscuro.length === 0);
  if (flojasOscuro.length) console.log("   flojas:", flojasOscuro.map((f) => f[0]).join(", "));
  await oscuro.close();

  // --- móvil -------------------------------------------------------------
  const movil = await navegador.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const pmovil = await movil.newPage();
  await entrar(pmovil, "pedro@elalamo.test");
  await pmovil.click('nav.nav button:has-text("Categorías")');
  await pmovil.waitForSelector("table.tabla");
  await capturar(pmovil, "45-categorias-movil-390");
  const desborde = await pmovil.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  revisar("En 390 px la página no se desplaza en horizontal", !desborde);
  await movil.close();
} finally {
  await navegador.close();
}

const fallas = resultados.filter((r) => !r.bien);
console.log(`\n${resultados.length - fallas.length}/${resultados.length} revisiones bien. Capturas en ${RAIZ}`);
process.exit(fallas.length === 0 ? 0 : 1);
