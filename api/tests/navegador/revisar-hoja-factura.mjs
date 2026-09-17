/**
 * Prueba manual asistida de la hoja de factura (CAL-01, CAL-04, docs/15).
 *
 * Recorre la vida entera de una factura: se escribe como borrador, se cierra,
 * se vuelve a abrir tal como se dejó, se revisa en vista previa, se emite, se
 * cobra y se anula.
 *
 * Uso: node api/tests/navegador/revisar-hoja-factura.mjs
 */
import { chromium } from "playwright";
import { capturar, contraste, crearRevisor, entrar, irA, salir, vigilar } from "./_ayudas.mjs";

const { revisar, cerrar } = crearRevisor();
const navegador = await chromium.launch();

/**
 * Espera a que los totales digan lo que tienen que decir.
 *
 * A propósito espera por lo que debe aparecer y no por lo que debe irse: una
 * espera de "que ya no ponga X" se cumple sola cuando la pantalla todavía no ha
 * calculado nada, y entonces la revisión siguiente da por bueno un cero.
 */
async function esperarTotal(pagina, esperado) {
  await pagina.waitForFunction(
    (valor) => document.querySelector("#totales-hoja")?.textContent?.includes(valor),
    esperado,
    { timeout: 8000 },
  );
  return pagina.locator("#totales-hoja").innerText();
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
  const contexto = await navegador.newContext({ viewport: { width: 1440, height: 1000 }, locale: "es-PR" });
  const pagina = await contexto.newPage();
  const { erroresJs } = vigilar(pagina);

  await entrar(pagina, "pedro@elalamo.test");

  // Contra esto se revisa el inventario al final, en vez de contra un número
  // fijo que obligaría a sembrar la base antes de cada corrida.
  const tortasAntes = await existenciaDe(pagina, "Torta");

  await irA(pagina, "Facturas");

  /** Lo que el resumen da por facturado ahora mismo. */
  const facturado = async () =>
    (await pagina.locator(".resumen").count())
      ? (await pagina.locator(".resumen__dato").nth(1).locator("b").innerText()).trim()
      : "0.00";

  const facturadoAntes = await facturado();

  // --- la hoja en blanco ---------------------------------------------------
  await pagina.getByRole("button", { name: "Nueva factura" }).click();
  await pagina.waitForSelector(".hoja");
  await capturar(pagina, "120-hoja-en-blanco");

  // La barra de la hoja es pegajosa: no puede taparle los menús a la barra de
  // la aplicación. Pasó una vez y no se ve hasta que alguien abre el selector
  // de empresa estando en esta pantalla.
  await pagina.locator(".barra-superior .desplegable__boton").first().click();
  await pagina.waitForSelector(".desplegable__menu");
  const menuEncima = await pagina.evaluate(() => {
    const menu = document.querySelector(".desplegable__menu").getBoundingClientRect();
    const debajo = document.elementFromPoint(menu.left + menu.width / 2, menu.bottom - 8);
    return debajo?.closest(".desplegable__menu") !== null;
  });
  revisar("El menú de la barra superior queda por encima de la hoja", menuEncima);
  await pagina.keyboard.press("Escape");
  await pagina.waitForSelector(".desplegable__menu", { state: "detached" });

  const barras = await pagina.evaluate(() => {
    const arriba = document.querySelector(".barra-superior").getBoundingClientRect();
    const hoja = document.querySelector(".hoja-barra").getBoundingClientRect();
    return Math.round(hoja.top) >= Math.round(arriba.bottom);
  });
  revisar("Y la barra de la hoja se aparca debajo, sin solaparse", barras);

  const membrete = await pagina.locator(".hoja__emisor").innerText();
  revisar("La hoja lleva el membrete de la empresa", membrete.includes("Panadería El Álamo"));
  revisar("Con su dirección y su teléfono", membrete.includes("Bayamón") && membrete.includes("787"));
  revisar("Y su registro de comerciante", membrete.includes("Registro"));
  revisar(
    "Avisa que el número se asigna al emitir",
    (await pagina.locator(".hoja__datos").innerText()).includes("Se asigna al emitir"),
  );
  const folioAnunciado = await pagina.locator("#hoja-folio").innerText();
  revisar(`Y adelanta cuál será (${folioAnunciado})`, /^F-\d{5}$/.test(folioAnunciado));
  revisar("Propone un lugar para el logotipo", (await pagina.locator(".hoja__logo").innerText()).includes("Sin logotipo"));

  // --- se escribe la factura -----------------------------------------------
  await pagina.selectOption("#producto-0", { label: "Torta chocolate media libra" });
  await pagina.fill("#cantidad-0", "2");
  await pagina.fill("#detalle-0", "Una con el nombre en letra azul");
  await pagina.waitForSelector("#totales-hoja");

  revisar("Al elegir el producto trae su precio", (await pagina.inputValue("#precio-0")) === "95.00");
  revisar("Y su tasa de impuesto", (await pagina.inputValue("#impuesto-0")) === "11.5");

  let totales = await esperarTotal(pagina, "211.85");
  revisar("Calcula mientras se escribe", totales.includes("190.00") && totales.includes("211.85"));
  revisar("Y desglosa el impuesto como lo declara la empresa", totales.includes("Estatal") && totales.includes("Municipal"));

  // Un renglón escrito a mano: no viene del catálogo pero paga impuesto igual.
  await pagina.getByRole("button", { name: "Agregar una línea" }).click();
  await pagina.fill("#descripcion-1", "Entrega a domicilio");
  await pagina.fill("#precio-1", "10.00");
  totales = await esperarTotal(pagina, "223.00");
  revisar("Un renglón escrito a mano suma y paga impuesto", totales.includes("200.00") && totales.includes("223.00"));

  // El impuesto de un renglón se puede corregir a mano.
  await pagina.fill("#impuesto-1", "0");
  totales = await esperarTotal(pagina, "221.85");
  revisar("La tasa de un renglón se puede cambiar en la hoja", totales.includes("221.85"));

  // Descuento global.
  await pagina.getByRole("button", { name: "Agregar un descuento" }).click();
  await pagina.fill("#descuento-valor", "10");
  totales = await esperarTotal(pagina, "199.67");
  revisar("El descuento se aplica y se ve", totales.includes("Descuento"));
  revisar("Y baja el total", totales.includes("199.67"));

  await pagina.fill("#hoja-vendedor", "Emily Ortiz");
  await pagina.fill("#hoja-referencia", "OC-4471");
  await pagina.fill("#hoja-notas", "Entregar antes de las 10 de la mañana.");
  await pagina.fill("#hoja-vence", "2026-12-24");
  await capturar(pagina, "121-hoja-escrita");

  revisar(
    "Avisa que hay cambios sin guardar",
    (await pagina.locator(".hoja-barra__estado").innerText()).includes("Sin guardar"),
  );

  // --- guardar como borrador -----------------------------------------------
  await pagina.getByRole("button", { name: "Guardar borrador" }).click();
  await pagina.waitForFunction(
    () => document.querySelector(".hoja-barra__estado")?.textContent?.includes("Guardado"),
    null,
    { timeout: 5000 },
  );
  await capturar(pagina, "122-borrador-guardado");

  revisar("Guardar deja constancia", (await pagina.locator(".hoja-barra__estado").innerText()).includes("Guardado"));
  revisar(
    "El borrador se marca como tal",
    (await pagina.locator(".hoja-barra .etiqueta").innerText()).toLowerCase() === "borrador",
  );
  revisar(
    "Y todavía no ha gastado número",
    (await pagina.locator(".hoja__datos").innerText()).includes("Se asigna al emitir"),
  );

  // --- el borrador en el listado -------------------------------------------
  await pagina.getByRole("button", { name: "Cerrar", exact: true }).click();
  await pagina.waitForSelector("table.tabla");
  await capturar(pagina, "123-listado-con-borrador");

  const resumen = await pagina.locator(".resumen").innerText();
  revisar("El listado cuenta los borradores aparte", resumen.includes("Borradores sin emitir"));
  revisar(
    `Y un borrador no cuenta como facturado (sigue en ${facturadoAntes})`,
    (await facturado()) === facturadoAntes,
  );
  revisar(
    "El borrador aparece sin número",
    (await pagina.locator("table.tabla tbody").innerText()).includes("Sin número"),
  );

  // --- se vuelve a abrir tal como se dejó ----------------------------------
  await pagina.getByRole("button", { name: "Seguir" }).first().click();
  await pagina.waitForSelector(".hoja");
  await pagina.waitForFunction(() => document.querySelector("#hoja-vendedor")?.value === "Emily Ortiz", null, { timeout: 5000 });

  revisar("Al reabrirlo conserva el vendedor", (await pagina.inputValue("#hoja-vendedor")) === "Emily Ortiz");
  revisar("La referencia", (await pagina.inputValue("#hoja-referencia")) === "OC-4471");
  revisar("El vencimiento", (await pagina.inputValue("#hoja-vence")) === "2026-12-24");
  revisar("La nota", (await pagina.inputValue("#hoja-notas")).includes("antes de las 10"));
  revisar("El detalle del renglón", (await pagina.inputValue("#detalle-0")).includes("letra azul"));
  revisar("Y el producto elegido", (await pagina.inputValue("#precio-0")) === "95.00");
  revisar("Con su descuento puesto", (await pagina.inputValue("#descuento-valor")) === "10");

  // --- vista previa ---------------------------------------------------------
  await pagina.getByRole("button", { name: "Vista previa" }).click();
  await pagina.waitForSelector(".previa-velo");
  await capturar(pagina, "124-vista-previa");

  const previa = await pagina.locator(".previa .hoja").innerText();
  revisar("La vista previa muestra la factura entera", previa.includes("Panadería El Álamo") && previa.includes("Entrega a domicilio"));
  revisar("Con el vendedor y la nota", previa.includes("Emily Ortiz") && previa.includes("antes de las 10"));
  revisar("Sin el botón de agregar renglones", !(await pagina.locator(".previa .hoja__agregar").count()));
  revisar("Y ofrece imprimir", Boolean(await pagina.getByRole("button", { name: "Imprimir" }).count()));

  await pagina.getByRole("button", { name: "Cerrar la vista previa" }).click();
  await pagina.waitForSelector(".previa-velo", { state: "detached" });

  // --- emitir ---------------------------------------------------------------
  await pagina.getByRole("button", { name: /Emitir por/ }).click();
  await pagina.waitForSelector("#producto-0", { state: "detached" });
  await capturar(pagina, "125-hoja-emitida");

  revisar(
    "Al emitir le pone el folio que había anunciado",
    (await pagina.locator("#hoja-folio").innerText()) === folioAnunciado,
  );
  revisar(
    "Y queda emitida",
    (await pagina.locator(".hoja-barra .etiqueta").innerText()).toLowerCase() === "emitida",
  );
  revisar("Ya no se puede escribir en ella", !(await pagina.locator(".hoja__agregar").count()));
  revisar(
    "Pero se sigue leyendo entera",
    (await pagina.locator(".hoja").innerText()).includes("Una con el nombre en letra azul"),
  );
  revisar(
    "El aviso confirma la emisión",
    (await pagina.locator(".avisos__nota").last().innerText()).includes(folioAnunciado),
  );

  // --- cobrar ---------------------------------------------------------------
  // Los rótulos de la tarjeta van en versalitas: innerText los devuelve en mayúsculas.
  const lateral = (await pagina.locator(".hoja-aside").innerText()).toLowerCase();
  revisar("La columna de la derecha pasa a hablar de cobros", lateral.includes("cobros"));
  revisar("Y ofrece cobrar el saldo", lateral.includes("cobrar 199.67"));

  await pagina.selectOption(".hoja-aside #metodo", "ath_movil");
  await pagina.getByRole("button", { name: /Cobrar/ }).click();
  await pagina.waitForFunction(
    () => !document.querySelector("#totales-hoja")?.textContent?.includes("Saldo"),
    null,
    { timeout: 5000 },
  );
  await capturar(pagina, "126-hoja-cobrada");

  revisar("Al cobrar desaparece el saldo", !(await pagina.locator("#totales-hoja").innerText()).includes("Saldo"));
  revisar(
    "Y la factura queda pagada",
    (await pagina.locator(".hoja-barra .etiqueta").innerText()).toLowerCase() === "pagada",
  );

  // --- anular ---------------------------------------------------------------
  pagina.once("dialog", (d) => d.accept("Prueba de anulación desde la hoja"));
  await pagina.getByRole("button", { name: "Anular esta factura" }).click();
  await pagina.waitForFunction(
    () => document.querySelector(".pantalla, .hoja-pantalla")?.textContent?.includes("Anulada:"),
    null,
    { timeout: 5000 },
  );
  await capturar(pagina, "127-hoja-anulada");
  revisar(
    "La anulación queda a la vista con su motivo",
    (await pagina.locator(".aviso").first().innerText()).includes("Prueba de anulación"),
  );

  // --- descartar un borrador ------------------------------------------------
  await pagina.getByRole("button", { name: "Cerrar", exact: true }).click();
  await pagina.waitForSelector("table.tabla");
  const filasAntes = await pagina.locator("table.tabla tbody tr").count();

  await pagina.getByRole("button", { name: "Nueva factura" }).click();
  await pagina.waitForSelector(".hoja");
  await pagina.selectOption("#producto-0", { label: "Café colado 12 oz" });
  await pagina.getByRole("button", { name: "Guardar borrador" }).click();
  await pagina.waitForFunction(
    () => document.querySelector(".hoja-barra__estado")?.textContent?.includes("Guardado"),
    null,
    { timeout: 5000 },
  );

  pagina.once("dialog", (d) => d.accept());
  await pagina.getByRole("button", { name: "Descartar este borrador" }).click();
  await pagina.waitForSelector("table.tabla");
  await pagina.waitForFunction(
    (esperadas) => document.querySelectorAll("table.tabla tbody tr").length === esperadas,
    filasAntes,
    { timeout: 5000 },
  );
  revisar(
    `Descartar un borrador lo quita del listado (${filasAntes} filas)`,
    (await pagina.locator("table.tabla tbody tr").count()) === filasAntes,
  );

  // --- dar de alta sin salir de la factura ----------------------------------
  await irA(pagina, "Facturas");
  await pagina.getByRole("button", { name: "Nueva factura" }).click();
  await pagina.waitForSelector(".hoja");

  await pagina.selectOption("#hoja-cliente", "__crear__");
  await pagina.waitForSelector(".dialogo");
  await capturar(pagina, "129-alta-de-cliente");
  revisar(
    "El desplegable de cliente ofrece crear uno",
    (await pagina.locator(".dialogo").innerText()).includes("Cliente nuevo"),
  );

  await pagina.fill("#vuelo-cliente-nombre", "Repostería La Esquina");
  await pagina.fill("#vuelo-cliente-telefono", "787-555-0100");
  await pagina.getByRole("button", { name: "Crear y usarlo" }).click();
  await pagina.waitForSelector(".dialogo", { state: "detached" });
  revisar(
    "Al crearlo queda puesto en la factura",
    (await pagina.locator("#hoja-cliente option:checked").innerText()).includes("La Esquina"),
  );

  await pagina.selectOption("#producto-0", "__crear__");
  await pagina.waitForSelector(".dialogo");
  await pagina.fill("#vuelo-producto-nombre", "Bandeja de quesitos");
  await pagina.fill("#vuelo-producto-precio", "18.00");
  await pagina.fill("#vuelo-producto-existencia", "12");
  await capturar(pagina, "130-alta-de-producto");
  await pagina.getByRole("button", { name: "Crear y usarlo" }).click();
  await pagina.waitForSelector(".dialogo", { state: "detached" });
  await esperarTotal(pagina, "20.07");

  revisar(
    "El renglón ofrece crear un producto y lo deja elegido",
    (await pagina.locator("#producto-0 option:checked").innerText()).includes("quesitos"),
  );
  revisar("Con su precio", (await pagina.inputValue("#precio-0")) === "18.00");
  revisar("Y con la tasa de la empresa", (await pagina.inputValue("#impuesto-0")) === "11.5");
  revisar(
    "Y los totales lo recogen enseguida",
    (await pagina.locator("#totales-hoja").innerText()).includes("20.07"),
  );

  // Un borrador nuevo sin guardar: se sale sin dejar rastro.
  pagina.once("dialog", (d) => d.accept());
  await pagina.getByRole("button", { name: "Cerrar", exact: true }).click();
  await pagina.waitForSelector("table.tabla");

  // --- quién puede dar de alta qué ------------------------------------------
  await salir(pagina);
  await entrar(pagina, "emily@elalamo.test");   // mostrador
  await irA(pagina, "Facturas");
  await pagina.getByRole("button", { name: "Nueva factura" }).click();
  await pagina.waitForSelector(".hoja");

  revisar(
    "La cajera puede crear un cliente al vuelo (CLI-02)",
    (await pagina.locator("#hoja-cliente").innerText()).includes("Crear un cliente"),
  );
  revisar(
    "Pero no un producto, que no administra el catálogo",
    !(await pagina.locator("#producto-0").innerText()).includes("Crear un producto"),
  );

  pagina.once("dialog", (d) => d.accept());
  await pagina.getByRole("button", { name: "Cerrar", exact: true }).click();
  await pagina.waitForSelector("table.tabla");
  await salir(pagina);
  await entrar(pagina, "pedro@elalamo.test");

  // --- la mercancía se movió una sola vez -----------------------------------
  const tortasDespues = await existenciaDe(pagina, "Torta");
  revisar(
    `Guardar borradores no movió el inventario; emitir y anular lo dejaron igual (${tortasAntes} → ${tortasDespues})`,
    tortasDespues === tortasAntes,
  );

  // --- tema oscuro ----------------------------------------------------------
  await irA(pagina, "Facturas");
  await pagina.getByRole("button", { name: "Nueva factura" }).click();
  await pagina.waitForSelector(".hoja");
  await pagina.evaluate(() => document.documentElement.setAttribute("data-tema", "oscuro"));
  await capturar(pagina, "128-hoja-oscura");

  const legible = await pagina.evaluate(() => {
    const hoja = document.querySelector(".hoja");
    const rotulo = document.querySelector(".hoja__rotulo");
    return [getComputedStyle(rotulo).color, getComputedStyle(hoja).backgroundColor];
  });
  const razon = contraste(legible[0], legible[1]);
  revisar(`Los rótulos se leen sobre la hoja en tema oscuro (${razon.toFixed(1)}:1)`, razon >= 4.5);

  revisar("Ningún error de JavaScript en todo el recorrido", erroresJs.length === 0);
  if (erroresJs.length) console.log(erroresJs);
} finally {
  await navegador.close();
}

process.exit(cerrar());
