/**
 * Prueba manual asistida de la hoja de cuadre (CAL-01, CAL-04, docs/16).
 *
 * Cierra un turno completo: se escriben las cifras, se ve lo que el sistema
 * facturó al lado, se guarda, se reabre y se comprueba quién puede hacer qué.
 *
 * Uso: node api/tests/navegador/revisar-cuadre.mjs
 */
import { chromium } from "playwright";
import { capturar, crearRevisor, entrar, irA, salir, vigilar } from "./_ayudas.mjs";

const { revisar, cerrar } = crearRevisor();
const navegador = await chromium.launch();

const AYER = new Date(Date.now() - 86400000).toISOString().slice(0, 10);

/** Abre el módulo de caja, que no está en el mismo grupo que ventas. */
async function irACaja(pagina) {
  // En pantalla chica el menú es un cajón cerrado: primero se abre.
  const hamburguesa = pagina.locator(".barra-superior__menu");
  if (await hamburguesa.isVisible().catch(() => false)) {
    await hamburguesa.click();
    await pagina.waitForTimeout(250);
  }

  await pagina.locator('.rail__item:has-text("Hoja de Cuadre")').click();
  await pagina.waitForSelector(".vacio-caja, table.tabla");
}

/** Espera a que el total de la hoja diga lo que tiene que decir. */
async function esperarTotal(pagina, selector, esperado) {
  await pagina.waitForFunction(
    ([sel, valor]) => document.querySelector(sel)?.textContent?.includes(valor),
    [selector, esperado],
    { timeout: 6000 },
  );
}

/** Escribe un turno completo en la hoja abierta. */
async function escribirTurno(pagina, { fecha, inicial, lectura, cambio, tarjeta, athMovil }) {
  if (fecha) await pagina.fill("#cuadre-fecha", fecha);
  await pagina.fill("#efectivo_inicial", inicial);
  await pagina.fill("#ventas_lectura", lectura);
  await pagina.fill("#efectivo_cambio", cambio);
  await pagina.fill("#tarjeta", tarjeta);
  await pagina.fill("#ath_movil", athMovil);
}

try {
  const contexto = await navegador.newContext({ viewport: { width: 1440, height: 1000 }, locale: "es-PR" });
  const pagina = await contexto.newPage();
  const { erroresJs } = vigilar(pagina);

  await entrar(pagina, "pedro@elalamo.test");

  // --- el módulo va en su propio grupo -------------------------------------
  const menu = await pagina.locator(".rail").innerText();
  revisar("La hoja de cuadre tiene su propio grupo en el menú", /CAJA/i.test(menu));
  revisar("Y no cuelga de Ventas", menu.indexOf("CAJA") > menu.indexOf("Clientes"));

  await irACaja(pagina);
  await capturar(pagina, "140-cuadres-vacio");
  revisar(
    "Abre con su estado vacío explicando para qué sirve",
    (await pagina.locator(".vacio-caja").innerText()).includes("Ninguna hoja"),
  );

  // --- una venta de hoy, para tener con qué comparar ------------------------
  await irA(pagina, "Facturas");
  await pagina.getByRole("button", { name: "Venta rápida" }).click();
  await pagina.waitForSelector('[role="dialog"]');
  await pagina.selectOption("#producto-0", { label: "Torta chocolate media libra" });
  await pagina.fill("#cantidad-0", "2");
  await pagina.waitForSelector("#totales-venta");
  await pagina.getByRole("button", { name: /Emitir por/ }).click();
  await pagina.waitForSelector(".hoja");
  const facturadoHoy = await pagina.locator("#hoja-total").innerText();
  await pagina.getByRole("button", { name: "Cerrar", exact: true }).click();
  await pagina.waitForSelector("table.tabla");

  // --- se escribe la hoja del turno de hoy ---------------------------------
  await irACaja(pagina);
  await pagina.getByRole("button", { name: "Cuadrar un turno" }).click();
  await pagina.waitForSelector(".cuadre");
  await capturar(pagina, "141-cuadre-en-blanco");

  revisar(
    "La hoja nueva abre en el turno que se acaba de trabajar",
    ["am", "pm"].includes(await pagina.inputValue("#cuadre-turno")),
  );
  revisar(
    "Y no ofrece imprimir lo que todavía no se ha guardado",
    await pagina.getByRole("button", { name: "Imprimir PDF" }).isDisabled(),
  );

  // Las mismas cifras con las que Luis enseñó los cálculos del sistema actual:
  // si esto cuadra, una hoja hecha aquí da lo mismo que una hecha allá.
  await escribirTurno(pagina, {
    inicial: "5000.00",
    lectura: "150000.00",     // a propósito distinto de lo facturado
    cambio: "12.00",
    tarjeta: "100000.00",
    athMovil: "154000.00",
  });

  await esperarTotal(pagina, "#venta-y-cambio", "155,000.00");
  revisar("Suma el efectivo del comienzo con la lectura", true);
  await esperarTotal(pagina, "#total-efectivo", "154,988.00");
  revisar("Descuenta el cambio apartado, y la tarjeta no toca la gaveta", true);

  // --- el contraste con lo facturado ---------------------------------------
  await pagina.waitForSelector(".contraste");
  const contrastes = await pagina.locator(".contraste").allInnerTexts();
  revisar(
    `Dice lo que el sistema facturó en ese turno (${facturadoHoy})`,
    contrastes.some((c) => c.includes(facturadoHoy)),
  );
  revisar(
    "Y avisa de la diferencia con lo escrito",
    contrastes.some((c) => c.includes("11.85")),
  );
  revisar(
    "Además de cuántas facturas fueron y en qué horas",
    /1 factura\(s\) entre las \d{2}:\d{2} y las \d{2}:\d{2}/.test(
      await pagina.locator("#ventana-turno").innerText(),
    ),
  );

  // --- gastos ---------------------------------------------------------------
  await pagina.fill("#gasto-descripcion-0", "Compra de agua");
  await pagina.fill("#gasto-monto-0", "150.00");
  await esperarTotal(pagina, "#totales-cuadre", "154,838.00");
  await capturar(pagina, "142-cuadre-escrito");

  const totales = await pagina.locator("#totales-cuadre").innerText();
  revisar("Los gastos se restan del depósito", totales.includes("150.00") && totales.includes("154,838.00"));
  revisar(
    "Y el total de ventas suma la tarjeta y el ATH Móvil al depósito",
    totales.includes("408,838.00"),
  );

  // --- guardar y volver al listado -----------------------------------------
  await pagina.getByRole("button", { name: "Guardar datos" }).click();
  // Se espera por el aviso del guardado, no por "que haya un aviso": el de la
  // factura de antes todavía está en pantalla y cumpliría esa espera solo.
  await pagina.waitForFunction(
    () => document.querySelector(".avisos")?.textContent?.includes("A depositar"),
    null,
    { timeout: 5000 },
  );
  revisar(
    "Al guardar confirma cuánto hay que depositar",
    (await pagina.locator(".avisos").innerText()).includes("A depositar 154,838.00"),
  );

  // --- el papel -------------------------------------------------------------
  const pdf = await pagina.evaluate(async () => {
    const listado = await fetch("http://localhost:8000/v1/cuadres", {
      credentials: "include",
      headers: { Accept: "application/json" },
    }).then((r) => r.json());

    const r = await fetch(`http://localhost:8000/v1/cuadres/${listado.datos[0].id}/pdf`, { credentials: "include" });
    const texto = await r.text();

    return { tipo: r.headers.get("content-type"), nombre: r.headers.get("x-nombre-archivo"), cabecera: texto.slice(0, 4) };
  });

  revisar("La hoja guardada se descarga en PDF", pdf.cabecera === "%PDF" && pdf.tipo === "application/pdf");
  revisar(`Con un nombre que dice de qué es (${pdf.nombre})`, /^cuadre-\d{4}-\d{2}-\d{2}-(am|pm)\.pdf$/.test(pdf.nombre));

  await pagina.getByRole("button", { name: "Cerrar", exact: true }).click();
  await pagina.waitForSelector("table.tabla");
  await capturar(pagina, "143-cuadres-listado");

  const fila = await pagina.locator("table.tabla tbody tr").first().innerText();
  revisar("La hoja aparece en el listado con sus cifras", fila.includes("154,838.00") && fila.includes("150,000.00"));
  revisar("Y dice quién la cuadró", fila.includes("Pedro Rivera"));

  const resumen = await pagina.locator(".resumen").innerText();
  revisar("El pie suma lo gastado y lo que hay que depositar", resumen.includes("150.00") && resumen.includes("154,838.00"));

  // --- se reabre tal como se dejó -------------------------------------------
  await pagina.getByRole("button", { name: "Abrir" }).first().click();
  await pagina.waitForSelector(".cuadre");
  await pagina.waitForFunction(() => document.querySelector("#ventas_lectura")?.value === "150000.00", null, { timeout: 5000 });

  revisar("Al reabrirla conserva la lectura", (await pagina.inputValue("#ventas_lectura")) === "150000.00");
  revisar("El efectivo del comienzo", (await pagina.inputValue("#efectivo_inicial")) === "5000.00");
  revisar("Y su gasto", (await pagina.inputValue("#gasto-descripcion-0")).includes("agua"));

  // --- no se cuadra dos veces el mismo turno --------------------------------
  await pagina.getByRole("button", { name: "Cerrar", exact: true }).click();
  await pagina.waitForSelector("table.tabla");
  await pagina.getByRole("button", { name: "Nueva hoja" }).click();
  await pagina.waitForSelector(".cuadre");
  await pagina.fill("#efectivo_inicial", "50.00");
  await pagina.getByRole("button", { name: "Guardar datos" }).click();
  await pagina.waitForSelector(".aviso");
  await capturar(pagina, "144-cuadre-repetido");
  revisar(
    "No deja cuadrar dos veces el mismo turno",
    (await pagina.locator(".aviso").first().innerText()).toLowerCase().includes("ya hay una hoja"),
  );

  // --- un depósito negativo se avisa ----------------------------------------
  await pagina.selectOption("#cuadre-turno", "pm");
  await pagina.fill("#cuadre-fecha", AYER);
  await escribirTurno(pagina, {
    inicial: "0",
    lectura: "100.00",
    cambio: "0",
    tarjeta: "0",
    athMovil: "0",
  });
  await pagina.fill("#gasto-descripcion-0", "Compra grande");
  await pagina.fill("#gasto-monto-0", "500.00");
  await esperarTotal(pagina, "#totales-cuadre", "-400.00");
  await capturar(pagina, "145-cuadre-negativo");
  revisar(
    "Un depósito negativo se explica en vez de esconderse",
    (await pagina.locator(".aviso").last().innerText()).includes("más efectivo del que quedó"),
  );

  // --- quién puede qué --------------------------------------------------------
  await salir(pagina);
  await entrar(pagina, "emily@elalamo.test");   // mostrador
  revisar(
    "La cajera tiene su hoja de cuadre",
    (await pagina.locator('.rail__item:has-text("Hoja de Cuadre")').count()) === 1,
  );
  await irACaja(pagina);
  revisar(
    "Pero no ve las de otros",
    (await pagina.locator(".vacio-caja").count()) === 1,
  );

  await salir(pagina);
  await entrar(pagina, "sonia@elalamo.test");   // contador
  await irACaja(pagina);
  revisar("El contador ve todas las hojas", (await pagina.locator("table.tabla tbody tr").count()) >= 1);
  revisar(
    "Y no se le ofrece cuadrar",
    (await pagina.getByRole("button", { name: "Nueva hoja" }).count()) === 0,
  );
  await pagina.getByRole("button", { name: "Ver" }).first().click();
  await pagina.waitForSelector(".cuadre");
  await capturar(pagina, "146-cuadre-contador");
  revisar(
    "La abre en solo lectura",
    (await pagina.getByRole("button", { name: "Guardar datos" }).count()) === 0 &&
      (await pagina.locator("#ventas_lectura").isDisabled()),
  );

  await salir(pagina);
  await entrar(pagina, "carmina@elalamo.test");   // almacén
  revisar(
    "Al de almacén no se le ofrece la caja",
    (await pagina.locator('.rail__item:has-text("Hoja de Cuadre")').count()) === 0,
  );

  revisar("Ningún error de JavaScript en todo el recorrido", erroresJs.length === 0);
  if (erroresJs.length) console.log(erroresJs);

  await contexto.close();

  // --- móvil ----------------------------------------------------------------
  const movil = await navegador.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const pm = await movil.newPage();
  await entrar(pm, "pedro@elalamo.test");
  await irACaja(pm);
  await pm.getByRole("button", { name: "Nueva hoja" }).click();
  await pm.waitForSelector(".cuadre");
  await capturar(pm, "147-cuadre-movil-390");
  const desborde = await pm.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  revisar("En 390 px la hoja no se desplaza en horizontal", !desborde);
  await movil.close();
} finally {
  await navegador.close();
}

process.exit(cerrar());
