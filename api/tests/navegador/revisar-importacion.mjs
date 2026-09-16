/**
 * Prueba manual asistida de importar y exportar productos (CAL-01, CAL-04).
 * Uso: node api/tests/navegador/revisar-importacion.mjs
 */
import { chromium } from "playwright";
import { readFileSync, writeFileSync, existsSync, rmSync } from "node:fs";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { capturar, crearRevisor, entrar, vigilar } from "./_ayudas.mjs";

const { revisar, cerrar } = crearRevisor();
const BOM = "﻿";

// Archivo con una fila buena, una sin nombre y una con categoría inventada.
const CON_ERRORES = join(tmpdir(), "aiop-catalogo.csv");
writeFileSync(
  CON_ERRORES,
  BOM +
    [
      "Nombre del producto;Codigo interno;Categoria;Precio de venta;Costo;Existencia;Existencia minima",
      "Bizcochuelo de guayaba;BIZ-01;Dulces;7.50;3.00;24;6",
      ";SIN-NOMBRE;Dulces;5.00;2.00;10;2",
      "Con categoria inventada;CAT-X;Chucherias;5.00;2.00;10;2",
    ].join("\n") + "\n",
  "utf8",
);

const navegador = await chromium.launch({ downloadsPath: tmpdir() });

try {
  const contexto = await navegador.newContext({
    viewport: { width: 1440, height: 950 },
    locale: "es-PR",
    acceptDownloads: true,
  });
  const pagina = await contexto.newPage();
  const { erroresJs } = vigilar(pagina);

  await entrar(pagina, "pedro@elalamo.test");
  await pagina.waitForSelector("table.tabla");

  // --- exportar lo que se está viendo (PRO-09) -------------------------
  await pagina.selectOption("#tipo", "servicio");
  await pagina.waitForFunction(
    () => document.querySelectorAll("table.tabla tbody tr").length === 2,
    null,
    { timeout: 5000 },
  );

  const descarga = pagina.waitForEvent("download");
  await pagina.click("text=Exportar");
  const archivo = await descarga;
  const rutaExportada = join(tmpdir(), "aiop-exportado.csv");
  await archivo.saveAs(rutaExportada);

  const exportado = readFileSync(rutaExportada, "utf8");
  revisar("La exportación se descarga con nombre propio", archivo.suggestedFilename().includes("servicio"));
  revisar("Trae solo lo que estaba filtrado", exportado.split("\n").filter((l) => l.trim()).length === 3);
  revisar("Y abre bien en Excel: lleva BOM", exportado.startsWith(BOM));
  revisar("El aviso confirma la exportación", (await pagina.locator(".avisos__nota").innerText()).includes("filtros"));

  await pagina.click("text=Limpiar filtros");
  await pagina.waitForSelector("table.tabla");

  // --- importar: paso 1 -------------------------------------------------
  await pagina.click("text=Importar");
  await pagina.waitForSelector('[role="dialog"]');
  await capturar(pagina, "90-importar-paso-1");
  revisar("El paso 1 ofrece la plantilla", (await pagina.locator("text=Descargar plantilla").count()) === 1);

  const plantilla = pagina.waitForEvent("download");
  await pagina.click("text=Descargar plantilla");
  const archivoPlantilla = await plantilla;
  revisar("La plantilla se descarga", archivoPlantilla.suggestedFilename() === "plantilla-productos.csv");

  // --- importar: paso 2, la vista previa -------------------------------
  await pagina.setInputFiles("#archivo", CON_ERRORES);
  await pagina.click("text=Revisar archivo");
  await pagina.waitForSelector(".resumen");
  await capturar(pagina, "91-importar-vista-previa");

  const resumen = await pagina.locator(".resumen").innerText();
  revisar("Cuenta una fila nueva y dos con error", /1[\s\S]*Nuevos/.test(resumen) && /2[\s\S]*Con error/.test(resumen));
  revisar(
    "Dice fila por fila qué está mal",
    (await pagina.locator(".errores-fila").first().innerText()).includes("Falta el nombre"),
  );
  revisar("Ofrece descargar lo que hay que arreglar", (await pagina.locator("text=Descargar lo que hay que arreglar").count()) === 1);

  // Nada se ha guardado todavía.
  const antes = await pagina.evaluate(async () => {
    const r = await fetch("http://localhost:8000/v1/productos?buscar=Bizcochuelo", { credentials: "include" });
    return (await r.json()).datos.length;
  });
  revisar("La vista previa no guardó nada todavía", antes === 0);

  // --- importar: paso 3, confirmar -------------------------------------
  await pagina.click("text=Importar 1 producto(s)");
  await pagina.waitForSelector('[role="dialog"]', { state: "detached" });
  await pagina.fill("#buscar", "Bizcochuelo");
  await pagina.waitForFunction(
    () => (document.querySelector("table.tabla tbody")?.innerText ?? "").includes("Bizcochuelo"),
    null,
    { timeout: 5000 },
  );
  await capturar(pagina, "92-importar-aplicada");

  const fila = await pagina.locator("table.tabla tbody tr", { hasText: "Bizcochuelo" }).innerText();
  revisar("El producto importado aparece en la tabla", fila.includes("BIZ-01"));
  revisar("Con su existencia de apertura", fila.includes("24"));
  revisar("Y con su margen calculado", fila.includes("60%"));
  revisar("El aviso dice cuántos entraron", (await pagina.locator(".avisos__nota").innerText()).includes("1 producto(s) nuevo(s)"));

  revisar("Sin errores de JavaScript", erroresJs.length === 0);
  if (erroresJs.length) console.log("   errores:", erroresJs.slice(0, 3));

  await contexto.close();
} finally {
  await navegador.close();
  for (const f of [CON_ERRORES, join(tmpdir(), "aiop-exportado.csv")]) {
    if (existsSync(f)) rmSync(f);
  }
}

process.exit(cerrar() === 0 ? 0 : 1);
