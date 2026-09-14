/**
 * Prueba manual asistida del módulo de Suplidores (CAL-01, CAL-04).
 *
 * Recorre lo que haría una persona: buscar, crear, equivocarse, corregir,
 * editar, desactivar y filtrar. Deja capturas como evidencia.
 *
 * Uso: node api/tests/navegador/revisar-suplidores.mjs
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

async function capturar(pagina, nombre) {
  await pagina.screenshot({ path: join(RAIZ, `${nombre}.png`), fullPage: true });
}

async function entrar(pagina, email) {
  await pagina.goto(EMPRESA, { waitUntil: "networkidle" });
  await pagina.fill("#email", email);
  await pagina.fill("#clave", CLAVE);
  await pagina.click('button[type="submit"]');

  // La app abre en Productos: hay que ir a Suplidores.
  await pagina.waitForSelector("nav.nav, .vacio-caja, .error, .aviso");
  const pestana = pagina.locator('nav.nav button:has-text("Suplidores")');
  if (await pestana.count()) await pestana.click();
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
      try { cuerpo = (await r.text()).slice(0, 300); } catch { cuerpo = "(sin cuerpo)"; }
      respuestasMalas.push(`${r.request().method()} ${r.url()} -> ${r.status()} ${cuerpo}`);
    }
  });
  const volcar = () => {
    if (respuestasMalas.length === 0) return;
    console.log("  respuestas con error:");
    for (const r of respuestasMalas) console.log("   " + r);
  };
  process.on("exit", volcar);

  // --- entra el propietario y cae en Suplidores ------------------------
  await entrar(pagina, "pedro@elalamo.test");
  await pagina.waitForSelector("table.tabla");
  await capturar(pagina, "20-suplidores-listado");

  const filas = await pagina.locator("table.tabla tbody tr").count();
  revisar(`El listado abre con los suplidores activos (${filas})`, filas === 4);
  // Las cabeceras van en versalitas por CSS, así que se compara sin distinguir mayúsculas.
  const cabeceras = (await pagina.locator("table.tabla thead th").allInnerTexts()).join("|").toLowerCase();
  revisar("La razón social y el vendedor son columnas distintas", cabeceras.includes("empresa") && cabeceras.includes("vendedor"));

  // --- búsqueda -------------------------------------------------------
  await pagina.fill("#buscar", "margarita");
  await pagina.waitForFunction(() => document.querySelectorAll("table.tabla tbody tr").length === 1, null, { timeout: 5000 });
  await capturar(pagina, "21-suplidores-busqueda");
  revisar("Busca por el nombre del vendedor", (await pagina.locator("table.tabla tbody tr").count()) === 1);

  await pagina.fill("#buscar", "zzz-no-existe");
  await pagina.waitForSelector(".vacio-caja");
  await capturar(pagina, "22-suplidores-sin-resultados");
  revisar("Sin resultados explica y ofrece limpiar", (await pagina.locator(".vacio-caja").innerText()).includes("Ningún suplidor coincide"));

  await pagina.click("text=Limpiar filtros");
  await pagina.waitForSelector("table.tabla");

  // --- alta con error de validación ------------------------------------
  await pagina.click("text=Nuevo suplidor");
  await pagina.waitForSelector('[role="dialog"]');
  await capturar(pagina, "23-suplidores-panel-nuevo");
  revisar("El alta abre en panel lateral, sin salir del listado", (await pagina.locator("table.tabla").count()) === 1);

  // Número de cliente repetido: el error tiene que salir junto al campo.
  await pagina.fill("#razon_social", "Suplidor de Prueba en Navegador");
  await pagina.fill("#numero_cliente", "4578");
  await pagina.click('button[form="formulario-suplidor"]');
  await pagina.waitForSelector("#numero_cliente-error");
  await capturar(pagina, "24-suplidores-error-numero-repetido");
  const mensaje = await pagina.locator("#numero_cliente-error").innerText();
  revisar("Avisa del número de cliente repetido", mensaje.includes("Ya tienes otro suplidor"));
  revisar("No pierde lo que ya estaba escrito", (await pagina.inputValue("#razon_social")) === "Suplidor de Prueba en Navegador");

  // Corrige y guarda.
  await pagina.fill("#numero_cliente", "9099");
  await pagina.fill("#vendedor", "Pruebas Automáticas");
  await pagina.fill("#telefono", "787-555-1234");
  await pagina.click('button[form="formulario-suplidor"]');
  await pagina.waitForSelector('[role="dialog"]', { state: "detached" });
  await pagina.fill("#buscar", "Prueba en Navegador");
  await pagina.waitForFunction(() => document.querySelectorAll("table.tabla tbody tr").length === 1, null, { timeout: 5000 });
  await capturar(pagina, "25-suplidores-creado");
  revisar("Guarda y el nuevo aparece en la tabla", (await pagina.locator("table.tabla tbody").innerText()).includes("Pruebas Automáticas"));

  // --- edición ---------------------------------------------------------
  await pagina.click("text=Editar");
  await pagina.waitForSelector('[role="dialog"]');
  revisar("Al editar, el panel llega con los datos cargados", (await pagina.inputValue("#vendedor")) === "Pruebas Automáticas");
  await pagina.fill("#terminos_pago", "45 días");
  await pagina.click('button[form="formulario-suplidor"]');
  await pagina.waitForSelector('[role="dialog"]', { state: "detached" });
  await pagina.waitForFunction(() => document.body.innerText.includes("45 días"), null, { timeout: 5000 });
  revisar("La edición se refleja en la tabla", (await pagina.locator("table.tabla tbody").innerText()).includes("45 días"));

  // --- desactivar ------------------------------------------------------
  pagina.once("dialog", (d) => d.accept());
  await pagina.click("text=Desactivar");
  await pagina.waitForFunction(() => document.querySelectorAll("table.tabla tbody tr").length === 0 || document.querySelector(".vacio-caja"), null, { timeout: 5000 });
  await pagina.selectOption("#estado", "inactivos");
  await pagina.waitForSelector("table.tabla");
  await capturar(pagina, "26-suplidores-inactivos");
  revisar("Al desactivar sale de los activos y sigue existiendo", (await pagina.locator("table.tabla tbody").innerText()).includes("Prueba en Navegador"));

  // --- rol sin permiso de edición ---------------------------------------
  await pagina.click("text=Cerrar sesión");
  await pagina.waitForSelector("#email");
  await entrar(pagina, "sonia@elalamo.test");
  await pagina.waitForSelector("table.tabla");
  await capturar(pagina, "27-suplidores-contador-solo-lectura");
  revisar("El contador ve la tabla", (await pagina.locator("table.tabla tbody tr").count()) > 0);
  revisar("El contador no ve el botón de alta", (await pagina.locator("text=Nuevo suplidor").count()) === 0);
  revisar("El contador no ve el botón de editar", (await pagina.locator("text=Editar").count()) === 0);

  // --- rol sin acceso al módulo ------------------------------------------
  await pagina.click("text=Cerrar sesión");
  await pagina.waitForSelector("#email");
  await entrar(pagina, "emily@elalamo.test");
  await pagina.waitForSelector(".empresas, .vacio-caja, table.tabla");
  await capturar(pagina, "28-empleado-mostrador-sin-modulo");
  revisar("Al empleado de mostrador no se le ofrece Suplidores", (await pagina.locator('nav.nav button:text("Suplidores")').count()) === 0);

  revisar("Sin errores de JavaScript", erroresJs.length === 0);
  if (erroresJs.length) console.log("   errores:", erroresJs.slice(0, 3));

  // --- móvil -------------------------------------------------------------
  const movil = await navegador.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const pmovil = await movil.newPage();
  await entrar(pmovil, "pedro@elalamo.test");
  await pmovil.waitForSelector("table.tabla");
  await capturar(pmovil, "29-suplidores-movil-390");
  const desborde = await pmovil.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  revisar("En 390 px la página no se desplaza en horizontal", !desborde);
  await movil.close();

  // --- tema oscuro --------------------------------------------------------
  const oscuro = await navegador.newContext({ viewport: { width: 1280, height: 900 }, colorScheme: "dark" });
  const poscuro = await oscuro.newPage();
  await entrar(poscuro, "pedro@elalamo.test");
  await poscuro.waitForSelector("table.tabla");
  await poscuro.click("text=Nuevo suplidor");
  await poscuro.waitForSelector('[role="dialog"]');
  await capturar(poscuro, "30-suplidores-tema-oscuro");
  const fondoTabla = await poscuro.evaluate(() => getComputedStyle(document.querySelector("table.tabla")).backgroundColor);
  revisar("La tabla se pinta con los colores del tema oscuro", fondoTabla !== "rgb(255, 255, 255)");
  await oscuro.close();

  await contexto.close();
} finally {
  await navegador.close();
}

const fallas = resultados.filter((r) => !r.bien);
console.log(`\n${resultados.length - fallas.length}/${resultados.length} revisiones bien. Capturas en ${RAIZ}`);
process.exit(fallas.length === 0 ? 0 : 1);
