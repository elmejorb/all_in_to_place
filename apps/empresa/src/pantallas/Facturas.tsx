import { useCallback, useEffect, useState } from "react";
import { Aviso, Boton, Campo, PanelLateral, useAvisar } from "@aiop/ui";
import { api, ErrorApi, type FacturaDetalle, type ListadoFacturas } from "../api";
import { NuevaFactura } from "./NuevaFactura";

const ETIQUETA_ESTADO: Record<string, string> = {
  borrador: "Borrador",
  emitida: "Emitida",
  pagada_parcial: "Pagada a medias",
  pagada: "Pagada",
  vencida: "Vencida",
  anulada: "Anulada",
};

const TONO_ESTADO: Record<string, string | undefined> = {
  pagada: "bien",
  vencida: "aviso",
  anulada: "aviso",
  pagada_parcial: "marca",
};

export function Facturas({ soloLectura }: { soloLectura: boolean }) {
  const [listado, setListado] = useState<ListadoFacturas | null>(null);
  const [buscar, setBuscar] = useState("");
  const [estado, setEstado] = useState("todas");
  const [cursor, setCursor] = useState<string | null>(null);
  const [cargando, setCargando] = useState(true);
  const [facturando, setFacturando] = useState(false);
  const [viendo, setViendo] = useState<FacturaDetalle | null>(null);
  const [fallo, setFallo] = useState<string | null>(null);
  const avisar = useAvisar();

  const cargar = useCallback(async () => {
    setCargando(true);
    setFallo(null);
    const p = new URLSearchParams({ estado });
    if (buscar.trim()) p.set("buscar", buscar.trim());
    if (cursor) p.set("cursor", cursor);

    try {
      setListado(await api.get<ListadoFacturas>(`/facturas?${p}`));
    } catch (e) {
      setFallo((e as ErrorApi).message);
    } finally {
      setCargando(false);
    }
  }, [buscar, estado, cursor]);

  useEffect(() => {
    const t = setTimeout(() => void cargar(), buscar ? 300 : 0);
    return () => clearTimeout(t);
  }, [cargar, buscar]);

  async function abrir(id: string) {
    setViendo(await api.get<FacturaDetalle>(`/facturas/${id}`));
  }

  const datos = listado?.datos ?? [];
  const resumen = listado?.resumen;
  const permisos = listado?.permisos ?? { facturar: false, anular: false };
  const puedeFacturar = permisos.facturar && !soloLectura;
  const hayFiltro = Boolean(buscar.trim() || estado !== "todas");

  return (
    <section className="pantalla">
      <div className="pantalla__cabeza">
        <div>
          <h1>Facturas</h1>
          <p>Lo que has vendido, lo que te han pagado y lo que falta por cobrar.</p>
        </div>
        {puedeFacturar && (
          <div className="pantalla__acciones">
            <Boton onClick={() => setFacturando(true)}>Nueva factura</Boton>
          </div>
        )}
      </div>

      {resumen && resumen.cantidad > 0 && (
        <div className="resumen">
          <div className="resumen__dato">
            <b>{resumen.cantidad}</b>
            <small>Facturas</small>
          </div>
          <div className="resumen__dato" data-tono="marca">
            <b className="numerica">{resumen.total}</b>
            <small>Facturado</small>
          </div>
          <div className="resumen__dato" data-tono={Number(resumen.por_cobrar) > 0 ? "peligro" : "bien"}>
            <b className="numerica">{resumen.por_cobrar}</b>
            <small>Por cobrar</small>
          </div>
        </div>
      )}

      <div className="filtros">
        <input
          id="buscar"
          type="search"
          placeholder="Buscar por folio o cliente"
          aria-label="Buscar facturas"
          value={buscar}
          onChange={(e) => {
            setCursor(null);
            setBuscar(e.target.value);
          }}
        />

        <select
          id="estado"
          aria-label="Estado"
          value={estado}
          onChange={(e) => {
            setCursor(null);
            setEstado(e.target.value);
          }}
        >
          <option value="todas">Todas</option>
          <option value="pendientes">Pendientes de cobro</option>
          <option value="pagada">Pagadas</option>
          <option value="vencida">Vencidas</option>
          <option value="anulada">Anuladas</option>
        </select>

        {hayFiltro && (
          <button
            type="button"
            className="boton-fila"
            onClick={() => {
              setBuscar("");
              setEstado("todas");
              setCursor(null);
            }}
          >
            Limpiar filtros
          </button>
        )}
      </div>

      {fallo && <Aviso>{fallo}</Aviso>}

      {cargando && !listado ? (
        <p className="vacio">Cargando...</p>
      ) : datos.length === 0 ? (
        <div className="vacio-caja">
          <h2>{hayFiltro ? "Ninguna factura coincide" : "Aún no has facturado"}</h2>
          <p>
            {hayFiltro
              ? "Prueba con otra búsqueda o cambia el filtro."
              : "Cuando emitas tu primera venta aparecerá aquí, con lo cobrado y lo pendiente."}
          </p>
          {puedeFacturar && !hayFiltro && <Boton onClick={() => setFacturando(true)}>Emitir la primera</Boton>}
        </div>
      ) : (
        <>
          <div className="tabla-caja tarjeta">
            <table className="tabla">
              <thead>
                <tr>
                  <th>Folio</th>
                  <th>Cliente</th>
                  <th>Estado</th>
                  <th className="derecha">Total</th>
                  <th className="derecha">Saldo</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {datos.map((f) => (
                  <tr key={f.id} data-inactivo={f.estado === "anulada"}>
                    <td>
                      <span className="celda-principal">
                        <b className="mono">{f.folio}</b>
                        <small>{f.emitida_en ? new Date(f.emitida_en).toLocaleDateString("es-PR") : "—"}</small>
                      </span>
                    </td>
                    <td data-etiqueta="Cliente">{f.cliente ?? <span className="tenue">Sin cliente</span>}</td>
                    <td data-etiqueta="Estado">
                      <span className="etiqueta" data-tono={TONO_ESTADO[f.estado]}>
                        {ETIQUETA_ESTADO[f.estado] ?? f.estado}
                      </span>
                    </td>
                    <td className="numerica derecha" data-etiqueta="Total">{f.total}</td>
                    <td className="numerica derecha" data-etiqueta="Saldo">
                      {Number(f.saldo) > 0 ? f.saldo : <span className="tenue">—</span>}
                    </td>
                    <td className="celda-acciones">
                      <div className="acciones">
                        <button type="button" className="boton-fila" onClick={() => void abrir(f.id)}>
                          Ver
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="paginacion">
            <span>{datos.length} en esta página</span>
            <Boton variante="suave" disabled={!listado?.anterior} onClick={() => setCursor(listado?.anterior ?? null)}>
              Anterior
            </Boton>
            <Boton variante="suave" disabled={!listado?.siguiente} onClick={() => setCursor(listado?.siguiente ?? null)}>
              Siguiente
            </Boton>
          </div>
        </>
      )}

      {facturando && (
        <NuevaFactura
          metodosPago={listado?.metodos_pago ?? []}
          alCerrar={() => setFacturando(false)}
          alEmitir={async (factura) => {
            setFacturando(false);
            setCursor(null);
            avisar(`Factura ${factura.folio} emitida por ${factura.total}.`);
            await cargar();
            setViendo(factura);
          }}
        />
      )}

      {viendo && (
        <DetalleFactura
          factura={viendo}
          puedeAnular={permisos.anular && !soloLectura}
          metodosPago={listado?.metodos_pago ?? []}
          alCerrar={() => setViendo(null)}
          alCambiar={async (actualizada) => {
            setViendo(actualizada);
            await cargar();
          }}
        />
      )}
    </section>
  );
}

function DetalleFactura({
  factura,
  puedeAnular,
  metodosPago,
  alCerrar,
  alCambiar,
}: {
  factura: FacturaDetalle;
  puedeAnular: boolean;
  metodosPago: string[];
  alCerrar: () => void;
  alCambiar: (f: FacturaDetalle) => Promise<void>;
}) {
  const [cobrando, setCobrando] = useState(false);
  const [metodo, setMetodo] = useState(metodosPago[0] ?? "efectivo");
  const [monto, setMonto] = useState(factura.saldo);
  const [trabajando, setTrabajando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const avisar = useAvisar();

  const pendiente = Number(factura.saldo) > 0 && factura.estado !== "anulada";

  async function cobrar() {
    setTrabajando(true);
    setError(null);
    try {
      const r = await api.post<FacturaDetalle>(`/facturas/${factura.id}/cobrar`, { metodo, monto });
      avisar(`Cobro registrado. Saldo: ${r.saldo}.`);
      setCobrando(false);
      await alCambiar(r);
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
      const r = await api.post<FacturaDetalle>(`/facturas/${factura.id}/anular`, { motivo });
      avisar(`Factura ${r.folio} anulada. La mercancía volvió al inventario.`);
      await alCambiar(r);
    } catch (e) {
      setError((e as ErrorApi).message);
    } finally {
      setTrabajando(false);
    }
  }

  return (
    <PanelLateral
      abierto
      titulo={`Factura ${factura.folio}`}
      descripcion={factura.cliente ?? "Sin cliente"}
      alCerrar={alCerrar}
      pie={
        <>
          {puedeAnular && factura.estado !== "anulada" && (
            <Boton variante="suave" type="button" onClick={() => void anular()} cargando={trabajando}>
              Anular
            </Boton>
          )}
          {pendiente && !cobrando && (
            <Boton type="button" onClick={() => setCobrando(true)}>Cobrar</Boton>
          )}
          {cobrando && (
            <Boton type="button" cargando={trabajando} onClick={() => void cobrar()}>
              Registrar cobro
            </Boton>
          )}
          {!pendiente && !cobrando && (
            <Boton variante="suave" type="button" onClick={alCerrar}>Cerrar</Boton>
          )}
        </>
      }
    >
      {error && <Aviso>{error}</Aviso>}

      {factura.estado === "anulada" && (
        <Aviso tono="aviso">Anulada: {factura.motivo_anulacion}</Aviso>
      )}

      {cobrando && (
        <div className="cobro">
          <div className="campo">
            <label htmlFor="metodo">Método de pago</label>
            <select id="metodo" value={metodo} onChange={(e) => setMetodo(e.target.value)}>
              {metodosPago.map((m) => (
                <option key={m} value={m}>{m.replace("_", " ")}</option>
              ))}
            </select>
          </div>
          <Campo id="monto" etiqueta="Monto" inputMode="decimal" value={monto} onChange={(e) => setMonto(e.target.value)} />
        </div>
      )}

      <div className="tabla-caja">
        <table className="tabla tabla--previa">
          <thead>
            <tr>
              <th>Concepto</th>
              <th className="derecha">Cant.</th>
              <th className="derecha">Precio</th>
              <th className="derecha">Total</th>
            </tr>
          </thead>
          <tbody>
            {factura.renglones.map((r) => (
              <tr key={r.id}>
                <td data-etiqueta="Concepto">
                  <span className="celda-principal">
                    <b>{r.descripcion}</b>
                    {r.sku && <small className="mono">{r.sku}</small>}
                  </span>
                </td>
                <td className="numerica derecha" data-etiqueta="Cantidad">{r.cantidad}</td>
                <td className="numerica derecha" data-etiqueta="Precio">{r.precio}</td>
                <td className="numerica derecha" data-etiqueta="Total">{r.total}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <dl className="totales" id="totales-factura">
        <div><dt>Subtotal</dt><dd className="numerica">{factura.subtotal}</dd></div>
        {Number(factura.descuento) > 0 && (
          <div><dt>Descuento</dt><dd className="numerica">−{factura.descuento}</dd></div>
        )}
        {factura.desglose.map((d) => (
          <div key={d.nombre}><dt>{d.nombre}</dt><dd className="numerica">{d.monto}</dd></div>
        ))}
        {factura.cliente_exento && (
          <div><dt>Impuesto</dt><dd className="tenue">Cliente exento</dd></div>
        )}
        <div data-total="true"><dt>Total</dt><dd className="numerica">{factura.total}</dd></div>
        {Number(factura.pagado) > 0 && (
          <div><dt>Pagado</dt><dd className="numerica">{factura.pagado}</dd></div>
        )}
        {Number(factura.saldo) > 0 && (
          <div data-alerta="true"><dt>Saldo</dt><dd className="numerica">{factura.saldo}</dd></div>
        )}
      </dl>

      {factura.pagos.length > 0 && (
        <div>
          <h3 className="titulo-menor">Cobros</h3>
          <ul className="lista-pagos">
            {factura.pagos.map((p) => (
              <li key={p.id}>
                <span>{p.metodo.replace("_", " ")}</span>
                <span className="numerica">{p.monto}</span>
                {Number(p.cambio) > 0 && <small className="tenue">cambio {p.cambio}</small>}
              </li>
            ))}
          </ul>
        </div>
      )}
    </PanelLateral>
  );
}
