import { useCallback, useEffect, useState } from "react";
import { Aviso, Boton, useAvisar } from "@aiop/ui";
import { api, ErrorApi, type ListadoFacturas } from "../api";
import { HojaFactura } from "./HojaFactura";
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

/**
 * El listado de facturas, y la puerta a las dos maneras de vender (docs/15).
 *
 * **Venta rápida** es el panel lateral: el cliente está esperando en el
 * mostrador y la venta se cierra en segundos. **Nueva factura** abre la hoja,
 * que es el documento entero, con borrador, vencimiento y nota. No son la misma
 * tarea y no convenía forzarlas en la misma pantalla.
 */
export function Facturas({ soloLectura }: { soloLectura: boolean }) {
  const [listado, setListado] = useState<ListadoFacturas | null>(null);
  const [buscar, setBuscar] = useState("");
  const [estado, setEstado] = useState("todas");
  const [cursor, setCursor] = useState<string | null>(null);
  const [cargando, setCargando] = useState(true);
  const [ventaRapida, setVentaRapida] = useState(false);
  /** null = se está viendo el listado. Con valor, la hoja abierta. */
  const [hoja, setHoja] = useState<{ id: string | null } | null>(null);
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

  const datos = listado?.datos ?? [];
  const resumen = listado?.resumen;
  const permisos = listado?.permisos ?? { facturar: false, anular: false };
  const puedeFacturar = permisos.facturar && !soloLectura;
  const hayFiltro = Boolean(buscar.trim() || estado !== "todas");

  // La hoja ocupa la pantalla entera: es un documento, no una ventana sobre
  // el listado.
  if (hoja) {
    return (
      <HojaFactura
        id={hoja.id}
        metodosPago={listado?.metodos_pago ?? []}
        puedeAnular={permisos.anular && !soloLectura}
        soloLectura={soloLectura}
        alCerrar={() => setHoja(null)}
        alCambiar={cargar}
      />
    );
  }

  return (
    <section className="pantalla">
      <div className="pantalla__cabeza">
        <div>
          <h1>Facturas</h1>
          <p>Lo que has vendido, lo que te han pagado y lo que falta por cobrar.</p>
        </div>
        {puedeFacturar && (
          <div className="pantalla__acciones">
            <Boton variante="suave" onClick={() => setVentaRapida(true)}>Venta rápida</Boton>
            <Boton onClick={() => setHoja({ id: null })}>Nueva factura</Boton>
          </div>
        )}
      </div>

      {resumen && (resumen.cantidad > 0 || resumen.borradores > 0) && (
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
          {resumen.borradores > 0 && (
            <button
              type="button"
              className="resumen__dato"
              id="ir-a-borradores"
              onClick={() => {
                setCursor(null);
                setEstado("borrador");
              }}
            >
              <b>{resumen.borradores}</b>
              <small>Borradores sin emitir</small>
            </button>
          )}
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
          <option value="borrador">Borradores</option>
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
          {puedeFacturar && !hayFiltro && <Boton onClick={() => setHoja({ id: null })}>Emitir la primera</Boton>}
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
                        <b className="mono">{f.folio ?? "Sin número"}</b>
                        <small>{f.fecha ? new Date(f.fecha + "T00:00").toLocaleDateString("es-PR") : "—"}</small>
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
                      {Number(f.saldo) > 0 && f.estado !== "borrador" ? f.saldo : <span className="tenue">—</span>}
                    </td>
                    <td className="celda-acciones">
                      <div className="acciones">
                        <button type="button" className="boton-fila" onClick={() => setHoja({ id: f.id })}>
                          {f.estado === "borrador" ? "Seguir" : "Ver"}
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

      {ventaRapida && (
        <NuevaFactura
          metodosPago={listado?.metodos_pago ?? []}
          alCerrar={() => setVentaRapida(false)}
          alEmitir={async (factura) => {
            setVentaRapida(false);
            setCursor(null);
            avisar(`Factura ${factura.folio} emitida por ${factura.total}.`);
            await cargar();
            setHoja({ id: factura.id });
          }}
        />
      )}
    </section>
  );
}
