/** Vistazo rápido a la interfaz, para revisarla con los ojos. */
import { chromium } from "playwright";
import { entrar, irA, capturar, RAIZ } from "./_ayudas.mjs";

const navegador = await chromium.launch();

// Escritorio, tema oscuro.
const oscuro = await navegador.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: "dark" });
const po = await oscuro.newPage();
await entrar(po, "pedro@elalamo.test");
await po.waitForSelector("table.tabla");
await po.waitForTimeout(500);
await capturar(po, "80-vistazo-oscuro");

// Panel lateral abierto, para ver el formulario denso.
await po.click("text=Nuevo producto");
await po.waitForSelector('[role="dialog"]');
await po.fill("#nombre", "Quesito de guayaba");
await po.fill("#costo", "1.20");
await po.fill("#precio", "3.00");
await po.waitForTimeout(300);
await capturar(po, "81-vistazo-panel-oscuro");
await oscuro.close();

// Móvil.
const movil = await navegador.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
const pm = await movil.newPage();
await entrar(pm, "pedro@elalamo.test");
await pm.waitForSelector("table.tabla");
await pm.waitForTimeout(400);
await capturar(pm, "82-vistazo-movil");
await pm.click(".barra-superior__menu");
await pm.waitForTimeout(400);
await capturar(pm, "83-vistazo-movil-menu");
await movil.close();

// Menú plegado y selector de empresa abierto.
const ctx = await navegador.newContext({ viewport: { width: 1440, height: 900 }, locale: "es-PR" });
const p = await ctx.newPage();
await entrar(p, "luis@elalamo.test");
await irA(p, "Suplidores");
await p.click(".rail__plegar");
await p.locator(".barra-superior .desplegable__boton").first().click();
await p.waitForTimeout(300);
await capturar(p, "84-vistazo-plegado-selector");
await ctx.close();

await navegador.close();
console.log("capturas en", RAIZ);
