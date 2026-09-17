import { useCallback, useEffect, useState } from "react";
import { Aviso, Boton } from "@aiop/ui";
import { api, enDinero, ErrorApi, type ListadoCuadres } from "../api";
import { HojaDeCuadre } from "./HojaDeCuadre";

/** El primer día del mes en curso, que es el rango que se consulta casi siempre. */
function primeroDelMes(): string {
  const h = new Date();
  return new Date(h.getFullYear(), h.getMonth(), 1).toISOString().slice(0, 10);
}

const hoy = () => new Date().toISOString().slice(0, 10);

const TURNO: Record<string, string> = { am: "Mañana", pm: "Tarde" };

/**
 * El histórico de cierres de caja (CAJ-09, CAJ-14).
 *
 * El pie no es decorativo: lo gastado y lo depositado en el rango es lo que se
 * lleva al banco y lo que se concilia con el estado de cuenta.
 */
export function Cuadres({ soloLectura }: { soloLectura: boolean }) {
  const [listado, setListado] = useState<ListadoCuadres | null>(null);
  const [desde, setDesde] = useState(primeroDelMes());
  const [hasta, setHasta] = useState(hoy());
  const [cargando, setCargando] = useState(true);
  const [fallo, setFallo] = useState<string | null>(null);
  /** null = se está viendo el listado. Con valor, la hoja abierta. */
  const [hoja, setHoja] = useState<{ id: string | null } | null>(null);

  const cargar = useCallback(async () => {
    setCargando(true);
    setFallo(null);

    try {
      setListado(await api.get<ListadoCuadres>(`/cuadres?desde=${desde}&hasta=${hasta}`));
    } catch (e) {
      setFallo((e as ErrorApi).message);
    } finally {
      setCargando(false);
    }
  }, [desde, hasta]);

  useEffect(() => {
    void cargar();
  }, [cargar]);

  const datos = listado?.datos ?? [];
  const resumen = listado?.resumen;
  const puedeCuadrar = (listado?.permisos.cuadrar ?? false) && !soloLectura;

  if (hoja) {
    return (
      <HojaDeCuadre
        id={hoja.id}
        puedeCuadrar={puedeCuadrar}
        fechaHoy={listado?.hoy ?? hoy()}
        turnoActual={listado?.turno_actual ?? "am"}
        alCerrar={() => setHoja(null)}
        alGuardar={cargar}
      />
    );
  }

  return (
    <section className="pantalla">
      <div className="pantalla__cabeza">
        <div>
          <h1>Hoja de cuadre</h1>
          <p>El cierre de caja de cada turno: lo que entró, lo que se gastó y lo que se deposita.</p>
        </div>
        {puedeCuadrar && (
          <div className="pantalla__acciones">
            <Boton onClick={() => setHoja({ id: null })}>Nueva hoja</Boton>
          </div>
        )}
      </div>

      {resumen && resumen.hojas > 0 && (
        <div className="resumen">
          <div className="resumen__dato">
            <b>{resumen.hojas}</b>
            <small>Hojas en el rango</small>
          </div>
          <div className="resumen__dato" data-tono="marca">
            <b className="numerica">{enDinero(resumen.ventas)}</b>
            <small>Ventas</small>
          </div>
          <div className="resumen__dato">
            <b className="numerica">{enDinero(resumen.gastos)}</b>
            <small>Gastos</small>
          </div>
          <div className="resumen__dato" data-tono="bien">
            <b className="numerica">{enDinero(resumen.a_depositar)}</b>
            <small>Para depositar</small>
          </div>
        </div>
      )}

      <div className="filtros">
        <div className="campo campo--enfila">
          <label htmlFor="desde">Desde</label>
          <input id="desde" type="date" value={desde} max={hasta} onChange={(e) => setDesde(e.target.value)} />
        </div>
        <div className="campo campo--enfila">
          <label htmlFor="hasta">Hasta</label>
          <input id="hasta" type="date" value={hasta} min={desde} onChange={(e) => setHasta(e.target.value)} />
        </div>
      </div>

      {fallo && <Aviso>{fallo}</Aviso>}

      {cargando && !listado ? (
        <p className="vacio">Cargando...</p>
      ) : datos.length === 0 ? (
        <div className="vacio-caja">
          <h2>Ninguna hoja en estas fechas</h2>
          <p>
            Cada turno se cierra con su hoja: el efectivo del comienzo, la lectura de la
            registradora, lo cobrado con tarjeta y los gastos del día.
          </p>
          {puedeCuadrar && <Boton onClick={() => setHoja({ id: null })}>Cuadrar un turno</Boton>}
        </div>
      ) : (
        <div className="tabla-caja tarjeta">
          <table className="tabla">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Horario</th>
                <th className="derecha">Efect. inic.</th>
                <th className="derecha">V. lectura</th>
                <th className="derecha">Efect. cambio</th>
                <th className="derecha">Total efect.</th>
                <th className="derecha">Gastos</th>
                <th className="derecha">Efect. depos.</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {datos.map((h) => (
                <tr key={h.id}>
                  <td>
                    <span className="celda-principal">
                      <b>{new Date(h.fecha + "T00:00").toLocaleDateString("es-PR")}</b>
                      {h.cuadro && <small>{h.cuadro}</small>}
                    </span>
                  </td>
                  <td data-etiqueta="Horario">
                    <span className="etiqueta">{TURNO[h.turno] ?? h.turno}</span>
                  </td>
                  <td className="numerica derecha" data-etiqueta="Efectivo inicial">{enDinero(h.efectivo_inicial)}</td>
                  <td className="numerica derecha" data-etiqueta="Ventas según lectura">{enDinero(h.ventas_lectura)}</td>
                  <td className="numerica derecha" data-etiqueta="Efectivo para cambio">{enDinero(h.efectivo_cambio)}</td>
                  <td className="numerica derecha" data-etiqueta="Total efectivo">{enDinero(h.total_efectivo)}</td>
                  <td className="numerica derecha" data-etiqueta="Gastos">{enDinero(h.gastos)}</td>
                  <td className="numerica derecha" data-etiqueta="Efectivo para depositar">
                    <b>{enDinero(h.a_depositar)}</b>
                  </td>
                  <td className="celda-acciones">
                    <div className="acciones">
                      <button type="button" className="boton-fila" onClick={() => setHoja({ id: h.id })}>
                        {puedeCuadrar ? "Abrir" : "Ver"}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
