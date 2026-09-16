import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Aviso, Boton, useAvisar } from "@aiop/ui";
import {
  api,
  ErrorApi,
  type Calculo,
  type Cliente,
  type FacturaDetalle,
  type Membrete,
  type Producto,
} from "../api";

type RenglonHoja = {
  clave: number;
  producto: string;
  descripcion: string;
  detalle: string;
  cantidad: string;
  precio: string;
  impuesto: string;
};

type Faltante = { producto: string; pedido: number; disponible: number };

let siguienteClave = 1;

function renglonVacio(impuesto = ""): RenglonHoja {
  return { clave: siguienteClave++, producto: "", descripcion: "", detalle: "", cantidad: "1", precio: "", impuesto };
}

const hoy = () => new Date().toISOString().slice(0, 10);

const ETIQUETA_ESTADO: Record<string, string> = {
  borrador: "Borrador",
  emitida: "Emitida",
  pagada_parcial: "Pagada a medias",
  pagada: "Pagada",
  vencida: "Vencida",
  anulada: "Anulada",
};

/**
 * La factura como hoja: la pantalla se parece al documento que va a recibir el
 * cliente (docs/15, FAC-01).
 *
 * Es la misma pantalla para escribir y para leer. Mientras es borrador se
 * escribe encima; en cuanto se emite, los mismos campos se vuelven texto y lo
 * que cambia es la columna de la derecha, que pasa de "cómo se cobra" a "qué se
 * cobró". Tener una sola vista del documento evita la incoherencia de que la
 * factura se vea de una manera al hacerla y de otra al consultarla.
 */
export function HojaFactura({
  id,
  metodosPago,
  puedeAnular,
  soloLectura,
  alCerrar,
  alCambiar,
}: {
  id: string | null;
  metodosPago: string[];
  puedeAnular: boolean;
  soloLectura: boolean;
  alCerrar: () => void;
  alCambiar: () => void | Promise<void>;
}) {
  const [membrete, setMembrete] = useState<Membrete | null>(null);
  const [clientes, setClientes] = useState<Cliente[]>([]);
  const [productos, setProductos] = useState<Producto[]>([]);
  const [documento, setDocumento] = useState<FacturaDetalle | null>(null);
  const [documentoId, setDocumentoId] = useState<string | null>(id);

  const [cliente, setCliente] = useState("");
  const [fecha, setFecha] = useState(hoy());
  const [venceEl, setVenceEl] = useState("");
  const [vendedor, setVendedor] = useState("");
  const [referencia, setReferencia] = useState("");
  const [notas, setNotas] = useState("");
  const [renglones, setRenglones] = useState<RenglonHoja[]>([renglonVacio()]);
  const [descuentoTipo, setDescuentoTipo] = useState("porcentaje");
  const [descuentoValor, setDescuentoValor] = useState("");
  const [conDescuento, setConDescuento] = useState(false);

  const [cobrarAhora, setCobrarAhora] = useState(false);
  const [metodo, setMetodo] = useState(metodosPago[0] ?? "efectivo");
  const [recibido, setRecibido] = useState("");

  const [calculo, setCalculo] = useState<Calculo | null>(null);
  const [cargando, setCargando] = useState(true);
  const [trabajando, setTrabajando] = useState(false);
  const [previa, setPrevia] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [faltantes, setFaltantes] = useState<Faltante[]>([]);
  const avisar = useAvisar();

  /**
   * Si hay cambios sin guardar se decide comparando lo que dice la hoja con lo
   * último que se guardó, no con una bandera y un temporizador: el temporizador
   * competía con el propio React y el aviso parpadeaba.
   *
   * `sellar` marca que la próxima versión de la hoja es la buena, porque acaba
   * de llegar del servidor.
   */
  const sellar = useRef(true);
  const [sello, setSello] = useState("");

  const editable = !soloLectura && (documento === null || documento.editable);

  /** Vuelca un documento del servidor en los campos de la hoja. */
  const rellenar = useCallback((d: FacturaDetalle) => {
    sellar.current = true;
    setDocumento(d);
    setDocumentoId(d.id);
    setCliente(d.cliente_id ?? "");
    setFecha(d.fecha ?? hoy());
    setVenceEl(d.vence_el ?? "");
    setVendedor(d.vendedor ?? "");
    setReferencia(d.referencia ?? "");
    setNotas(d.notas ?? "");
    setDescuentoTipo(d.descuento_tipo ?? "porcentaje");
    setDescuentoValor(d.descuento_valor ?? "");
    setConDescuento(Boolean(d.descuento_valor) || Number(d.descuento) > 0);
    setRenglones(
      d.renglones.length > 0
        ? d.renglones.map((r) => ({
            clave: siguienteClave++,
            producto: r.producto ?? "",
            descripcion: r.descripcion,
            detalle: r.detalle ?? "",
            cantidad: String(r.cantidad),
            precio: r.precio,
            impuesto: r.tasa,
          }))
        : [renglonVacio()],
    );
  }, []);

  useEffect(() => {
    // Una carga que ya no manda no escribe nada. Sin esto, la respuesta de una
    // carga anterior vuelve tarde y borra lo que se acaba de escribir: en modo
    // estricto React monta el efecto dos veces, y la segunda tanda de datos
    // pisaba los renglones que ya se habían tecleado.
    let vigente = true;

    void (async () => {
      try {
        const [m, c, p] = await Promise.all([
          api.get<Membrete>("/empresa"),
          api.get<{ datos: Cliente[] }>("/clientes?por_pagina=100"),
          api.get<{ datos: Producto[] }>("/productos?por_pagina=100"),
        ]);
        if (!vigente) return;

        setMembrete(m);
        setClientes(c.datos);
        setProductos(p.datos);

        if (id) {
          const factura = await api.get<FacturaDetalle>(`/facturas/${id}`);
          if (!vigente) return;
          rellenar(factura);
        } else {
          // Una hoja nueva arranca con la tasa de la empresa puesta: es la que
          // se cobra salvo que el producto diga otra cosa.
          sellar.current = true;
          setRenglones([renglonVacio(m.impuesto_tasa)]);
        }
      } catch (e) {
        if (vigente) setError((e as ErrorApi).message);
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    return () => {
      vigente = false;
    };
  }, [id, rellenar]);

  const listos = useMemo(
    () => renglones.filter((r) => (r.producto || r.descripcion.trim()) && Number(r.cantidad) > 0),
    [renglones],
  );

  const cuerpo = useCallback(
    () => ({
      cliente: cliente || null,
      fecha,
      vence_el: venceEl || null,
      vendedor: vendedor || null,
      referencia: referencia || null,
      notas: notas || null,
      descuento_tipo: conDescuento && descuentoValor ? descuentoTipo : null,
      descuento_valor: conDescuento ? descuentoValor || null : null,
      renglones: listos.map((r) => ({
        producto: r.producto || null,
        descripcion: r.descripcion || null,
        detalle: r.detalle || null,
        cantidad: r.cantidad,
        precio: r.precio || null,
        impuesto: r.impuesto === "" ? null : r.impuesto,
      })),
    }),
    [cliente, fecha, venceEl, vendedor, referencia, notas, conDescuento, descuentoTipo, descuentoValor, listos],
  );

  // Totales en vivo, calculados por el mismo código que emite: lo que se ve en
  // pantalla es exactamente lo que se va a cobrar.
  useEffect(() => {
    if (!editable) return;

    if (listos.length === 0) {
      setCalculo(null);
      return;
    }

    const t = setTimeout(() => {
      void api.post<Calculo>("/facturas/calcular", cuerpo()).then(setCalculo).catch(() => undefined);
    }, 250);

    return () => clearTimeout(t);
  }, [cuerpo, listos.length, editable]);

  // La hoja en una cadena: lo que se compara para saber si falta guardar.
  const huella = JSON.stringify(cuerpo());
  const sucio = huella !== sello;

  // Sin lista de dependencias: hay que mirarlo después de cada render, no solo
  // cuando la hoja cambia. Lo normal al guardar es que no cambie nada —se envía
  // lo mismo que se recibe—, y atado al cambio el sello no llegaba nunca.
  useEffect(() => {
    if (!sellar.current) return;
    sellar.current = false;
    setSello(huella);
  });

  function cambiar(clave: number, campo: keyof RenglonHoja, valor: string) {
    setRenglones((actuales) =>
      actuales.map((r) => {
        if (r.clave !== clave) return r;

        const nuevo = { ...r, [campo]: valor };

        // Al elegir producto se traen su precio, su nombre y su tasa; los tres
        // se pueden cambiar después.
        if (campo === "producto") {
          const p = productos.find((x) => x.id === valor);
          if (p) {
            nuevo.precio = p.precio;
            nuevo.descripcion = p.nombre;
            nuevo.impuesto = p.impuesto;
          } else {
            nuevo.descripcion = "";
            nuevo.precio = "";
            nuevo.impuesto = membrete?.impuesto_tasa ?? "";
          }
        }

        return nuevo;
      }),
    );
  }

  // Mientras se escribe manda el cálculo en vivo; si todavía no ha llegado
  // —un borrador recién abierto— valen los totales con que se guardó. Antes
  // enseñaba 0.00 durante un instante, que en una factura asusta.
  const totales = (editable ? calculo : null) ?? documento;
  const desglose = totales?.desglose ?? [];
  const saldo = documento ? Number(documento.saldo) : 0;
  const anulada = documento?.estado === "anulada";

  // --- acciones -------------------------------------------------------------

  async function guardar(): Promise<FacturaDetalle | null> {
    setTrabajando(true);
    setError(null);

    try {
      const guardada = documentoId
        ? await api.put<FacturaDetalle>(`/facturas/${documentoId}`, cuerpo())
        : await api.post<FacturaDetalle>("/facturas/borradores", cuerpo());

      rellenar(guardada);
      avisar("Borrador guardado.");
      await alCambiar();
      return guardada;
    } catch (e) {
      setError((e as ErrorApi).message);
      return null;
    } finally {
      setTrabajando(false);
    }
  }

  async function emitir(permitirSinExistencia = false) {
    setTrabajando(true);
    setError(null);
    setFaltantes([]);

    const pagos = cobrarAhora
      // Sin monto: el servidor cobra el saldo. Mandar el total calculado aquí
      // falla si el cálculo viene a medio refrescar.
      ? [{ metodo, monto: null, recibido: metodo === "efectivo" && recibido ? recibido : null }]
      : [];

    const datos = { ...cuerpo(), pagos, permitir_sin_existencia: permitirSinExistencia };

    try {
      const emitida = documentoId
        ? await api.post<FacturaDetalle>(`/facturas/${documentoId}/emitir`, datos)
        : await api.post<FacturaDetalle>("/facturas", datos);

      rellenar(emitida);
      avisar(`Factura ${emitida.folio} emitida por ${emitida.total}.`);
      await alCambiar();
    } catch (e) {
      const fallo = e as ErrorApi;

      if (fallo.codigo === "sin_existencia") {
        // No es un error: es una pregunta para quien está facturando (FAC-08).
        setFaltantes((fallo.cuerpo.faltantes as Faltante[]) ?? []);
      } else {
        setError(fallo.message);
      }
    } finally {
      setTrabajando(false);
    }
  }

  async function cobrar() {
    setTrabajando(true);
    setError(null);
    try {
      const r = await api.post<FacturaDetalle>(`/facturas/${documentoId}/cobrar`, {
        metodo,
        recibido: metodo === "efectivo" && recibido ? recibido : null,
      });
      rellenar(r);
      avisar(`Cobro registrado. Saldo: ${r.saldo}.`);
      await alCambiar();
    } catch (e) {
      setError((e as ErrorApi).message);
    } finally {
      setTrabajando(false);
    }
  }

  async function anular() {
    const motivo = window.prompt("¿Por qué se anula esta factura? Queda registrado.");
    if (!motivo) return;

    setTrabajando(true);
    setError(null);
    try {
      const r = await api.post<FacturaDetalle>(`/facturas/${documentoId}/anular`, { motivo });
      rellenar(r);
      avisar(`Factura ${r.folio} anulada. La mercancía volvió al inventario.`);
      await alCambiar();
    } catch (e) {
      setError((e as ErrorApi).message);
    } finally {
      setTrabajando(false);
    }
  }

  async function descartar() {
    if (!window.confirm("¿Descartar este borrador? No se puede deshacer.")) return;

    setTrabajando(true);
    try {
      if (documentoId) await api.borrar(`/facturas/${documentoId}`);
      avisar("Borrador descartado.");
      await alCambiar();
      alCerrar();
    } catch (e) {
      setError((e as ErrorApi).message);
      setTrabajando(false);
    }
  }

  function cerrar() {
    if (sucio && !window.confirm("Hay cambios sin guardar. ¿Salir de todas formas?")) return;
    alCerrar();
  }

  const cambio =
    metodo === "efectivo" && recibido && totales
      ? Math.round((Number(recibido.replace(",", ".")) - Number(totales.total)) * 100) / 100
      : null;

  if (cargando) return <p className="vacio">Cargando la factura...</p>;

  const titulo = documento?.folio
    ? `Factura ${documento.folio}`
    : documentoId
      ? "Borrador de factura"
      : "Nueva factura";

  /** Una fecha como se lee en Puerto Rico, no como la guarda la base. */
  const enFecha = (v: string) => (v ? new Date(v + "T00:00").toLocaleDateString("es-PR") : "—");

  /**
   * Dibuja la hoja. `escribible` decide si cada dato es un control o es texto.
   *
   * La vista previa y la factura ya emitida usan la misma versión en texto: así
   * lo que se revisa antes de mandar es exactamente lo que se manda, y no una
   * hoja con cajas de formulario disfrazadas.
   */
  const hojaDe = (escribible: boolean) => (
    <article className="hoja">
      <header className="hoja__membrete">
        <div className="hoja__logo">
          {membrete?.logo ? (
            <img src={membrete.logo} alt={membrete.nombre} />
          ) : (
            <>
              <span>Sin logotipo</span>
              <small>Se configura una vez en los datos de la empresa</small>
            </>
          )}
        </div>

        <div className="hoja__emisor">
          <b>{membrete?.nombre}</b>
          {membrete?.registro_comerciante && <span>Registro {membrete.registro_comerciante}</span>}
          {membrete?.direccion && <span>{membrete.direccion}</span>}
          {membrete?.telefono && <span>{membrete.telefono}</span>}
          {membrete?.email && <span>{membrete.email}</span>}
        </div>
      </header>

      <section className="hoja__datos">
        <div className="hoja__campo">
          {escribible ? (
            <>
              <label htmlFor="hoja-cliente">Facturar a</label>
              <select id="hoja-cliente" value={cliente} onChange={(e) => setCliente(e.target.value)}>
                <option value="">Cliente de mostrador</option>
                {clientes.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.nombre}{c.exento ? " (exento)" : ""}
                  </option>
                ))}
              </select>
            </>
          ) : (
            <>
              <span className="hoja__rotulo">Facturar a</span>
              <b>{clientes.find((c) => c.id === cliente)?.nombre ?? documento?.cliente ?? "Cliente de mostrador"}</b>
              {documento?.cliente_exento && <small className="tenue">Exento de impuesto</small>}
            </>
          )}
        </div>

        <div className="hoja__campo">
          {escribible ? (
            <>
              <label htmlFor="hoja-fecha">Fecha</label>
              <input id="hoja-fecha" type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} />
              <label htmlFor="hoja-vence" className="hoja__separado">Vencimiento</label>
              <input id="hoja-vence" type="date" value={venceEl} onChange={(e) => setVenceEl(e.target.value)} />
            </>
          ) : (
            <>
              <span className="hoja__rotulo">Fecha</span>
              <b>{enFecha(fecha)}</b>
              <span className="hoja__rotulo hoja__separado">Vencimiento</span>
              <b>{venceEl ? enFecha(venceEl) : "Al contado"}</b>
            </>
          )}
        </div>

        <div className="hoja__campo">
          <span className="hoja__rotulo">Número</span>
          <b className="hoja__folio" id="hoja-folio">
            {documento?.folio ?? membrete?.serie.proximo_folio ?? "—"}
          </b>
          {!documento?.folio && <small className="tenue">Se asigna al emitir</small>}

          {escribible ? (
            <>
              <label htmlFor="hoja-referencia" className="hoja__separado">Referencia</label>
              <input
                id="hoja-referencia"
                value={referencia}
                placeholder="Orden de compra del cliente"
                onChange={(e) => setReferencia(e.target.value)}
              />
            </>
          ) : (
            referencia && (
              <>
                <span className="hoja__rotulo hoja__separado">Referencia</span>
                <b>{referencia}</b>
              </>
            )
          )}
        </div>

        <div className="hoja__campo hoja__gran-total">
          <span className="hoja__rotulo">Total a pagar ({membrete?.moneda})</span>
          <b id="hoja-total">{totales?.total ?? "0.00"}</b>
          {/* Un borrador no debe nada todavía: hablar de saldo pendiente antes
              de emitir es decirle al cliente que ya se le cobró. */}
          {documento && !editable && !anulada && saldo > 0 && (
            <small className="tenue">Saldo pendiente {documento.saldo}</small>
          )}
        </div>
      </section>

      <section>
        <table className="renglones-hoja">
          <thead>
            <tr>
              <th>Descripción</th>
              <th className="derecha" style={{ width: "6.5rem" }}>Precio</th>
              <th className="derecha" style={{ width: "5rem" }}>Cant.</th>
              <th className="derecha" style={{ width: "5rem" }}>Imp. %</th>
              <th className="derecha" style={{ width: "7rem" }}>Total</th>
              {escribible && <th style={{ width: "2rem" }} />}
            </tr>
          </thead>
          <tbody>
            {renglones.map((r, i) => {
              const enVivo = calculo?.renglones?.[listos.findIndex((l) => l.clave === r.clave)];
              const guardado = documento?.renglones[i];
              const total = (editable ? enVivo?.total : undefined) ?? guardado?.total ?? "—";

              return (
                <tr className="renglon-hoja" key={r.clave}>
                  <td>
                    <div className="renglon-hoja__concepto">
                      {escribible ? (
                        <>
                          <select
                            id={`producto-${i}`}
                            aria-label={`Producto del renglón ${i + 1}`}
                            value={r.producto}
                            onChange={(e) => cambiar(r.clave, "producto", e.target.value)}
                          >
                            <option value="">Escribir a mano</option>
                            {productos.map((p) => (
                              <option key={p.id} value={p.id}>{p.nombre}</option>
                            ))}
                          </select>

                          {!r.producto && (
                            <input
                              id={`descripcion-${i}`}
                              aria-label={`Descripción del renglón ${i + 1}`}
                              value={r.descripcion}
                              placeholder="Concepto"
                              onChange={(e) => cambiar(r.clave, "descripcion", e.target.value)}
                            />
                          )}

                          <textarea
                            id={`detalle-${i}`}
                            className="renglon-hoja__detalle"
                            aria-label={`Detalle del renglón ${i + 1}`}
                            rows={1}
                            value={r.detalle}
                            placeholder="Detalle (opcional)"
                            onChange={(e) => cambiar(r.clave, "detalle", e.target.value)}
                          />
                        </>
                      ) : (
                        <>
                          <b>{r.descripcion || "Sin descripción"}</b>
                          {r.detalle && <small className="tenue">{r.detalle}</small>}
                          {guardado?.sku && <small className="mono tenue">{guardado.sku}</small>}
                        </>
                      )}
                    </div>
                  </td>

                  <td className="derecha" data-rotulo="Precio">
                    {escribible ? (
                      <input
                        id={`precio-${i}`}
                        aria-label={`Precio del renglón ${i + 1}`}
                        inputMode="decimal"
                        value={r.precio}
                        placeholder="0.00"
                        onChange={(e) => cambiar(r.clave, "precio", e.target.value)}
                      />
                    ) : (
                      <span className="numerica">{r.precio}</span>
                    )}
                  </td>

                  <td className="derecha" data-rotulo="Cant.">
                    {escribible ? (
                      <input
                        id={`cantidad-${i}`}
                        aria-label={`Cantidad del renglón ${i + 1}`}
                        inputMode="decimal"
                        value={r.cantidad}
                        onChange={(e) => cambiar(r.clave, "cantidad", e.target.value)}
                      />
                    ) : (
                      <span className="numerica">{r.cantidad}</span>
                    )}
                  </td>

                  <td className="derecha" data-rotulo="Imp. %">
                    {escribible ? (
                      <input
                        id={`impuesto-${i}`}
                        aria-label={`Impuesto del renglón ${i + 1}`}
                        inputMode="decimal"
                        value={r.impuesto}
                        onChange={(e) => cambiar(r.clave, "impuesto", e.target.value)}
                      />
                    ) : (
                      <span className="numerica">{r.impuesto}</span>
                    )}
                  </td>

                  <td className="derecha renglon-hoja__total" data-rotulo="Total">
                    {total}
                    {faltantes.some((f) => f.producto === r.descripcion) && (
                      <small className="renglon-hoja__aviso">Sin existencia</small>
                    )}
                  </td>

                  {escribible && (
                    <td>
                      <button
                        type="button"
                        className="renglon-hoja__quitar"
                        aria-label={`Quitar el renglón ${i + 1}`}
                        onClick={() => setRenglones((a) => (a.length > 1 ? a.filter((x) => x.clave !== r.clave) : a))}
                      >
                        ×
                      </button>
                    </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>

        {escribible && (
          <button
            type="button"
            className="hoja__agregar"
            onClick={() => setRenglones((a) => [...a, renglonVacio(membrete?.impuesto_tasa ?? "")])}
          >
            + Agregar una línea
          </button>
        )}
      </section>

      <section className="hoja__cierre">
        <div className="hoja__campo">
          {escribible ? (
            <>
              <label htmlFor="hoja-vendedor">Vendedor</label>
              <input
                id="hoja-vendedor"
                value={vendedor}
                placeholder="Quién hizo la venta"
                onChange={(e) => setVendedor(e.target.value)}
              />

              <label htmlFor="hoja-notas" className="hoja__separado">Notas</label>
              <textarea
                id="hoja-notas"
                rows={3}
                value={notas}
                placeholder="Lo que deba ver el cliente en la factura"
                onChange={(e) => setNotas(e.target.value)}
              />
            </>
          ) : (
            <>
              {vendedor && (
                <>
                  <span className="hoja__rotulo">Vendedor</span>
                  <b>{vendedor}</b>
                </>
              )}
              {notas && (
                <>
                  <span className="hoja__rotulo hoja__separado">Notas</span>
                  <p className="hoja__nota">{notas}</p>
                </>
              )}
            </>
          )}
        </div>

        <dl className="totales-hoja" id="totales-hoja">
          <div className="totales-hoja__linea">
            <dt>Subtotal</dt>
            <dd>{totales?.subtotal ?? "0.00"}</dd>
          </div>

          {escribible && !conDescuento && (
            <button type="button" className="hoja__enlace" onClick={() => setConDescuento(true)}>
              Agregar un descuento
            </button>
          )}

          {conDescuento && (
            <div className="totales-hoja__linea">
              <dt>
                {escribible ? (
                  <span className="hoja__descuento">
                    <select
                      id="descuento-tipo"
                      aria-label="Tipo de descuento"
                      value={descuentoTipo}
                      onChange={(e) => setDescuentoTipo(e.target.value)}
                    >
                      <option value="porcentaje">Descuento %</option>
                      <option value="monto">Descuento $</option>
                    </select>
                    <input
                      id="descuento-valor"
                      aria-label="Valor del descuento"
                      inputMode="decimal"
                      value={descuentoValor}
                      onChange={(e) => setDescuentoValor(e.target.value)}
                    />
                  </span>
                ) : (
                  `Descuento${descuentoTipo === "porcentaje" && descuentoValor ? ` (${descuentoValor}%)` : ""}`
                )}
              </dt>
              <dd>−{totales?.descuento ?? "0.00"}</dd>
            </div>
          )}

          {desglose.length > 0 ? (
            desglose.map((d) => (
              <div className="totales-hoja__linea" key={d.nombre}>
                <dt>{d.nombre}</dt>
                <dd>{d.monto}</dd>
              </div>
            ))
          ) : (
            <div className="totales-hoja__linea">
              <dt>Impuesto</dt>
              <dd>{totales?.impuesto ?? "0.00"}</dd>
            </div>
          )}

          <div className="totales-hoja__linea" data-total="true">
            <dt>Total</dt>
            <dd>{totales?.total ?? "0.00"}</dd>
          </div>

          {documento && Number(documento.pagado) > 0 && (
            <div className="totales-hoja__linea">
              <dt>Pagado</dt>
              <dd>−{documento.pagado}</dd>
            </div>
          )}

          {documento && saldo > 0 && !anulada && !editable && (
            <div className="totales-hoja__linea" data-alerta="true">
              <dt>Saldo</dt>
              <dd>{documento.saldo}</dd>
            </div>
          )}

          {documento?.emitida_por && (
            <div className="totales-hoja__linea" data-tenue="true">
              <dt>Emitida por</dt>
              <dd>{documento.emitida_por}</dd>
            </div>
          )}
        </dl>
      </section>
    </article>
  );

  return (
    <section className="hoja-pantalla">
      <div className="hoja-barra">
        <div className="hoja-barra__titulo">
          <h1>{titulo}</h1>
          {documento && (
            <span
              className="etiqueta"
              data-tono={documento.estado === "pagada" ? "bien" : documento.estado === "anulada" ? "aviso" : "marca"}
            >
              {ETIQUETA_ESTADO[documento.estado] ?? documento.estado}
            </span>
          )}
        </div>

        <div className="hoja-barra__acciones">
          {editable && (
            <span className="hoja-barra__estado" data-sucio={sucio}>
              {sucio ? "Sin guardar" : documentoId ? "Guardado" : ""}
            </span>
          )}
          <Boton variante="fantasma" type="button" onClick={cerrar}>Cerrar</Boton>
          <Boton variante="suave" type="button" onClick={() => setPrevia(true)}>Vista previa</Boton>

          {editable && (
            <>
              <Boton variante="suave" type="button" cargando={trabajando} onClick={() => void guardar()}>
                Guardar borrador
              </Boton>
              <Boton type="button" cargando={trabajando} disabled={listos.length === 0} onClick={() => void emitir()}>
                {totales && listos.length > 0 ? `Emitir por ${totales.total}` : "Emitir factura"}
              </Boton>
            </>
          )}
        </div>
      </div>

      <div>
        {error && <Aviso>{error}</Aviso>}

        {anulada && <Aviso tono="aviso">Anulada: {documento?.motivo_anulacion}</Aviso>}

        {faltantes.length > 0 && (
          <div className="faltantes" id="aviso-faltantes">
            <Aviso tono="aviso">
              No hay existencia suficiente:
              <ul>
                {faltantes.map((f) => (
                  <li key={f.producto}>
                    {f.producto}: piden {f.pedido}, quedan {f.disponible}
                  </li>
                ))}
              </ul>
            </Aviso>
            <Boton variante="suave" type="button" cargando={trabajando} onClick={() => void emitir(true)}>
              Vender igual
            </Boton>
          </div>
        )}

        {/* La previa enseña la misma hoja; dibujarla dos veces repetiría cada
            identificador del documento. */}
        {!previa && hojaDe(editable)}
      </div>

      <aside className="hoja-aside">
        <div className="tarjeta-config">
          <div className="tarjeta-config__cabeza">
            <h2>Para esta factura</h2>
            <p>{editable ? "Cómo se cobra y cuándo vence" : "Lo que se cobró"}</p>
          </div>

          {editable && (
            <div className="tarjeta-config__bloque">
              <h3>Cobro</h3>
              <label className="interruptor" htmlFor="cobrar_ahora">
                <input
                  id="cobrar_ahora"
                  type="checkbox"
                  checked={cobrarAhora}
                  onChange={(e) => setCobrarAhora(e.target.checked)}
                />
                Se cobra al emitir
              </label>

              {cobrarAhora && (
                <>
                  <div className="campo">
                    <label htmlFor="metodo">Método</label>
                    <select id="metodo" value={metodo} onChange={(e) => setMetodo(e.target.value)}>
                      {metodosPago.map((m) => (
                        <option key={m} value={m}>{m.replace("_", " ")}</option>
                      ))}
                    </select>
                  </div>

                  {metodo === "efectivo" && (
                    <div className="campo">
                      <label htmlFor="recibido">Recibido</label>
                      <input
                        id="recibido"
                        inputMode="decimal"
                        value={recibido}
                        placeholder="0.00"
                        onChange={(e) => setRecibido(e.target.value)}
                      />
                    </div>
                  )}

                  {cambio !== null && (
                    <div className="cambio-vivo" id="cambio-vivo" data-falta={cambio < 0}>
                      <span>{cambio < 0 ? "Falta" : "Cambio"}</span>
                      <b>{Math.abs(cambio).toFixed(2)}</b>
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {documento && !editable && (
            <div className="tarjeta-config__bloque">
              <h3>Cobros</h3>
              {documento.pagos.length === 0 && <p className="tenue">Todavía no se ha cobrado nada.</p>}
              {documento.pagos.map((p) => (
                <div className="tarjeta-config__dato" key={p.id}>
                  <span>{p.metodo.replace("_", " ")}</span>
                  <b>{p.monto}</b>
                </div>
              ))}

              {saldo > 0 && !anulada && (
                <>
                  <div className="campo">
                    <label htmlFor="metodo">Método</label>
                    <select id="metodo" value={metodo} onChange={(e) => setMetodo(e.target.value)}>
                      {metodosPago.map((m) => (
                        <option key={m} value={m}>{m.replace("_", " ")}</option>
                      ))}
                    </select>
                  </div>
                  <Boton type="button" cargando={trabajando} onClick={() => void cobrar()}>
                    Cobrar {documento.saldo}
                  </Boton>
                </>
              )}
            </div>
          )}

          <div className="tarjeta-config__bloque">
            <h3>Condiciones</h3>
            <div className="tarjeta-config__dato">
              <span>Términos</span>
              <b>{documento?.terminos_pago ?? "Al contado"}</b>
            </div>
            <div className="tarjeta-config__dato">
              <span>Vence</span>
              <b>{venceEl ? enFecha(venceEl) : "Sin vencimiento"}</b>
            </div>
            {membrete && (
              <div className="tarjeta-config__dato">
                <span>Serie</span>
                <b>{membrete.serie.nombre}</b>
              </div>
            )}
          </div>

          {((editable && documentoId) || (documento && !editable && puedeAnular && !anulada)) && (
            <div className="tarjeta-config__bloque">
              {editable && documentoId && (
                <button type="button" className="hoja__enlace" data-tono="peligro" onClick={() => void descartar()}>
                  Descartar este borrador
                </button>
              )}
              {documento && !editable && puedeAnular && !anulada && (
                <button type="button" className="hoja__enlace" data-tono="peligro" onClick={() => void anular()}>
                  Anular esta factura
                </button>
              )}
            </div>
          )}
        </div>
      </aside>

      {previa && (
        <div className="previa-velo" role="dialog" aria-label="Vista previa de la factura">
          <div className="previa-caja">
            <div className="previa-caja__barra">
              <Boton variante="suave" type="button" onClick={() => window.print()}>Imprimir</Boton>
              <Boton type="button" onClick={() => setPrevia(false)}>Cerrar la vista previa</Boton>
            </div>
            {/* Siempre en texto: lo que se revisa es lo que se imprime. */}
            <div className="previa">{hojaDe(false)}</div>
          </div>
        </div>
      )}
    </section>
  );
}
