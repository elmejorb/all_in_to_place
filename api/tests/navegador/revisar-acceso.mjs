/**
 * Prueba manual asistida del flujo de acceso (CAL-01, CAL-04).
 *
 * Recorre lo que haría una persona: entrar, equivocarse de clave, entrar bien,
 * cambiar de empresa y salir. Deja capturas como evidencia.
 *
 * Uso: node api/tests/navegador/revisar-acceso.mjs
 */
import { chromium } from "playwright";
import { entrar, irA, abrirEmpresas, salir } from "./_ayudas.mjs";
import { mkdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), "capturas");
mkdirSync(RAIZ, { recursive: true });

const EMPRESA = "http://localhost:5173";
const CONSOLA = "http://localhost:5174";
const CLAVE = "Clave.Segura.1";

const resultados = [];

/** Contraste segun WCAG 2.1: (L1 + .05) / (L2 + .05). AA pide 4.5 en texto normal. */
function contraste(rgb1, rgb2) {
  const luminancia = (rgb) => {
    const [r, g, b] = rgb.match(/\d+/g).slice(0, 3).map(Number).map((v) => {
      const c = v / 255;
      return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const a = luminancia(rgb1);
  const b = luminancia(rgb2);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

function revisar(descripcion, condicion) {
  resultados.push({ descripcion, bien: Boolean(condicion) });
  console.log(`${condicion ? "  ok  " : " FALLA"}  ${descripcion}`);
}

async function capturar(pagina, nombre) {
  await pagina.screenshot({ path: join(RAIZ, `${nombre}.png`), fullPage: true });
}

async function acceder(pagina, url, email, clave) {
  await pagina.goto(url, { waitUntil: "networkidle" });
  await pagina.fill("#email", email);
  await pagina.fill("#clave", clave);
  await pagina.click('button[type="submit"]');
  // Ojo: no vale esperar por #email, que sigue en pantalla mientras se envia.
  await pagina.waitForSelector(".estructura, .barra-superior, .aviso, .error");
}


const navegador = await chromium.launch();

try {
  // --- app de empresa, escritorio ------------------------------------
  const contexto = await navegador.newContext({ viewport: { width: 1280, height: 860 }, locale: "es-PR" });
  const pagina = await contexto.newPage();

  // Un 422 o un 403 provocados a propósito aparecen en la consola del navegador
  // como "Failed to load resource": eso no es un defecto. Lo que no puede haber
  // es un error de JavaScript.
  const erroresJs = [];
  const erroresConsola = [];
  pagina.on("console", (m) => {
    if (m.type() === "error" && !m.text().includes("Failed to load resource")) erroresConsola.push(m.text());
  });
  pagina.on("pageerror", (e) => erroresJs.push(String(e)));

  await pagina.goto(EMPRESA, { waitUntil: "networkidle" });
  await capturar(pagina, "01-empresa-acceso");
  revisar("La pantalla de acceso muestra el título", await pagina.locator("h1").innerText() === "Entra a tu empresa");
  revisar("El foco arranca en el campo de correo", await pagina.evaluate(() => document.activeElement?.id) === "email");

  // Clave incorrecta: el error se ve junto al campo y no se pierde lo escrito.
  await acceder(pagina, EMPRESA, "pedro@elalamo.test", "incorrecta");
  await pagina.waitForSelector(".error, .aviso");
  await capturar(pagina, "02-empresa-clave-incorrecta");
  revisar("Muestra el error de credenciales", (await pagina.locator(".error, .aviso").count()) > 0);
  revisar("Conserva el correo escrito", await pagina.inputValue("#email") === "pedro@elalamo.test");

  // Entrada correcta con una sola empresa.
  await acceder(pagina, EMPRESA, "pedro@elalamo.test", CLAVE);
  await abrirEmpresas(pagina);
  await capturar(pagina, "03-empresa-dentro-una-empresa");
  revisar("Entra y muestra la empresa activa", (await pagina.locator('.empresa[data-activa="true"]').count()) === 1);
  revisar("La barra superior muestra la empresa activa", (await pagina.locator(".barra-superior .desplegable__boton").first().innerText()).includes("lamo"));

  await salir(pagina);

  // Usuario con dos empresas: selector y cambio.
  await acceder(pagina, EMPRESA, "luis@elalamo.test", CLAVE);
  await abrirEmpresas(pagina);
  await capturar(pagina, "04-empresa-selector-dos-empresas");
  revisar("Con dos empresas ofrece las dos", (await pagina.locator(".empresa").count()) === 2);
  revisar("No elige ninguna por él", (await pagina.locator('.empresa[data-activa="true"]').count()) === 0);

  // Al elegir empresa se entra a trabajar en ella: el selector no es un fin.
  const elegida = (await pagina.locator(".empresa b").first().innerText()).trim();
  await pagina.locator(".empresa").first().click();
  await pagina.waitForSelector("table.tabla, .vacio-caja");
  await capturar(pagina, "05-empresa-cambiada");
  const enBarra = await pagina.locator(".barra-superior .desplegable__boton").first().innerText();
  revisar(`Al elegir, entra a trabajar en esa empresa (${elegida})`, enBarra.includes(elegida));

  await abrirEmpresas(pagina);
  revisar("Y queda marcada como actual en la lista", (await pagina.locator('.empresa[data-activa="true"]').count()) === 1);

  // Empresa suspendida: aviso de solo lectura.
  await salir(pagina);
  await acceder(pagina, EMPRESA, "carlos@innovacion.test", CLAVE);
  await pagina.waitForSelector(".aviso");
  await abrirEmpresas(pagina);
  await capturar(pagina, "06-empresa-suspendida-solo-lectura");
  revisar("Avisa que la empresa está en solo lectura", (await pagina.locator(".aviso").innerText()).includes("suspendida"));

  // Usuario sin empresa: no entra.
  await salir(pagina);
  await acceder(pagina, EMPRESA, "sinempresa@aiop.test", CLAVE);
  await pagina.waitForSelector(".aviso, .error");
  await capturar(pagina, "07-empresa-usuario-sin-empresa");
  revisar("El usuario sin empresa no entra", (await pagina.locator("#email").count()) === 1);

  revisar("Sin errores de JavaScript", erroresJs.length === 0);
  revisar("Sin errores inesperados en la consola del navegador", erroresConsola.length === 0);
  if (erroresJs.length || erroresConsola.length) console.log("   errores:", [...erroresJs, ...erroresConsola].slice(0, 3));

  // --- móvil 390 px (RNF-03) -----------------------------------------
  const movil = await navegador.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const pmovil = await movil.newPage();
  await acceder(pmovil, EMPRESA, "luis@elalamo.test", CLAVE);
  await abrirEmpresas(pmovil);
  await capturar(pmovil, "08-empresa-movil-390");
  const desborde = await pmovil.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  revisar("En 390 px no hay desplazamiento horizontal", !desborde);
  await movil.close();

  // --- tema oscuro (IU-03) -------------------------------------------
  const oscuro = await navegador.newContext({ viewport: { width: 1100, height: 800 }, colorScheme: "dark" });
  const poscuro = await oscuro.newPage();
  await poscuro.goto(EMPRESA, { waitUntil: "networkidle" });
  await capturar(poscuro, "09-empresa-tema-oscuro");
  const fondo = await poscuro.evaluate(() => getComputedStyle(document.body).backgroundColor);
  revisar("El tema oscuro pinta un fondo oscuro", fondo !== "rgb(243, 246, 244)");

  // El botón principal tiene que seguir legible en los dos temas (RNF-04).
  for (const [nombre, pag] of [["claro", pagina], ["oscuro", poscuro]]) {
    const colores = await pag.evaluate(() => {
      const b = document.querySelector("button.boton");
      if (!b) return null;
      const e = getComputedStyle(b);
      return [e.color, e.backgroundColor];
    });
    if (colores) {
      const razon = contraste(colores[0], colores[1]);
      revisar(`Contraste AA del botón en tema ${nombre} (${razon.toFixed(2)}:1)`, razon >= 4.5);
    }
  }

  await oscuro.close();

  // --- consola de plataforma -----------------------------------------
  const cconsola = await navegador.newContext({ viewport: { width: 1280, height: 860 } });
  const pconsola = await cconsola.newPage();
  await pconsola.goto(CONSOLA, { waitUntil: "networkidle" });
  await capturar(pconsola, "10-consola-acceso");

  // Un usuario de empresa no entra por la consola (ARQ-03).
  await acceder(pconsola, CONSOLA, "pedro@elalamo.test", CLAVE);
  await pconsola.waitForSelector(".error, .aviso");
  await capturar(pconsola, "11-consola-rechaza-usuario-de-empresa");
  revisar("La consola rechaza a un usuario de empresa", (await pconsola.locator("#email").count()) === 1);

  await acceder(pconsola, CONSOLA, "laura@aiop.test", CLAVE);
  await pconsola.waitForSelector(".barra-superior");
  await capturar(pconsola, "12-consola-dentro");
  revisar("El usuario de plataforma entra a la consola", (await pconsola.locator(".barra-superior").innerText()).includes("superadmin"));

  // La sesión de la consola no sirve en la app de empresa.
  const cruzada = await pconsola.evaluate(async () => {
    const r = await fetch("http://localhost:8000/v1/yo", { credentials: "include", headers: { Accept: "application/json" } });
    return r.status;
  });
  revisar("La sesión de la consola no vale en la app de empresa", cruzada === 401);

  await cconsola.close();
  await contexto.close();
} finally {
  await navegador.close();
}

const fallas = resultados.filter((r) => !r.bien);
console.log(`\n${resultados.length - fallas.length}/${resultados.length} revisiones bien. Capturas en ${RAIZ}`);
process.exit(fallas.length === 0 ? 0 : 1);
