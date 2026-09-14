/**
 * Ayudas compartidas por los recorridos de navegador.
 *
 * Aquí viven los selectores de la estructura de la aplicación —entrar, navegar,
 * salir, cambiar de empresa—, para que un cambio de interfaz se arregle en un
 * solo archivo y no en cuatro.
 */
import { mkdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

export const EMPRESA = "http://localhost:5173";
export const CONSOLA = "http://localhost:5174";
export const CLAVE = "Clave.Segura.1";

export const RAIZ = join(dirname(fileURLToPath(import.meta.url)), "capturas");
mkdirSync(RAIZ, { recursive: true });

export function crearRevisor() {
  const resultados = [];

  const revisar = (descripcion, condicion) => {
    resultados.push({ descripcion, bien: Boolean(condicion) });
    console.log(`${condicion ? "  ok  " : " FALLA"}  ${descripcion}`);
  };

  const cerrar = () => {
    const fallas = resultados.filter((r) => !r.bien);
    console.log(`\n${resultados.length - fallas.length}/${resultados.length} revisiones bien. Capturas en ${RAIZ}`);
    return fallas.length;
  };

  return { revisar, cerrar };
}

export const capturar = (pagina, nombre) =>
  pagina.screenshot({ path: join(RAIZ, `${nombre}.png`), fullPage: true });

/** Registra errores de JavaScript y respuestas 4xx/5xx de la API. */
export function vigilar(pagina) {
  const erroresJs = [];
  const respuestasMalas = [];

  pagina.on("pageerror", (e) => erroresJs.push(String(e)));
  pagina.on("response", async (r) => {
    if (r.status() >= 400 && r.url().includes("/v1/")) {
      let cuerpo = "";
      try {
        cuerpo = (await r.text()).slice(0, 250);
      } catch {
        cuerpo = "(sin cuerpo)";
      }
      respuestasMalas.push(`${r.request().method()} ${r.url()} -> ${r.status()} ${cuerpo}`);
    }
  });

  process.on("exit", () => {
    if (!respuestasMalas.length) return;
    console.log("  respuestas con error:");
    for (const r of respuestasMalas) console.log("   " + r);
  });

  return { erroresJs, respuestasMalas };
}

export async function entrar(pagina, email, { url = EMPRESA, clave = CLAVE } = {}) {
  await pagina.goto(url, { waitUntil: "networkidle" });
  await pagina.fill("#email", email);
  await pagina.fill("#clave", clave);
  await pagina.click('button[type="submit"]');
  // Ojo: no vale esperar por #email, que sigue en pantalla mientras se envia.
  await pagina.waitForSelector(".estructura, .barra-superior, .aviso, .error");
}

/** Va a un módulo del menú lateral. */
export async function irA(pagina, titulo) {
  const item = pagina.locator(`.rail__item:has-text("${titulo}")`);
  if (!(await item.count())) return false;

  // En pantallas chicas el menú es un cajón cerrado: primero se abre.
  const hamburguesa = pagina.locator(".barra-superior__menu");
  if (await hamburguesa.isVisible().catch(() => false)) {
    await hamburguesa.click();
    await pagina.waitForTimeout(250);
  }

  await item.first().click();
  await pagina.waitForSelector("table.tabla, .vacio-caja");
  return true;
}

/** Abre la pantalla con todas las empresas, desde el selector de la barra. */
export async function abrirEmpresas(pagina) {
  const selector = pagina.locator(".barra-superior .desplegable__boton").first();
  if (!(await selector.count())) return false;
  await selector.click();
  await pagina.click("text=Ver todas mis empresas");
  await pagina.waitForSelector(".empresas");
  return true;
}

/** Cambia de empresa desde el desplegable de la barra superior. */
export async function cambiarEmpresa(pagina, nombre) {
  await pagina.locator(".barra-superior .desplegable__boton").first().click();
  await pagina.locator(`.desplegable__menu .menu__item:has-text("${nombre}")`).first().click();
  await pagina.waitForSelector(".desplegable__menu", { state: "detached" });
}

export async function salir(pagina) {
  await pagina.locator(".barra-superior__acciones .desplegable__boton").click();
  await pagina.click("text=Cerrar sesión");
  await pagina.waitForSelector("#email");
}

/** Contraste WCAG entre dos colores ya calculados por el navegador. */
export function contraste(rgb1, rgb2) {
  const luminancia = (rgb) => {
    const [r, g, b] = rgb
      .match(/\d+/g)
      .slice(0, 3)
      .map(Number)
      .map((v) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
      });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };

  const a = luminancia(rgb1);
  const b = luminancia(rgb2);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}
