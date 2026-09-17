import { useCallback, useEffect, useMemo, useState } from "react";
import { Aviso, Boton, useAvisar } from "@aiop/ui";
import {
  aCentavos,
  api,
  aTexto,
  descargar,
  ErrorApi,
  type CuadreDetalle,
  type FacturadoEnTurno,
  type GastoCuadre,
} from "../api";

type Gasto = GastoCuadre & { clave: number };

let siguienteClave = 1;

const gastoVacio = (): Gasto => ({ clave: siguienteClave++, descripcion: "", monto: "" });

const TURNO = { am: "Mañana (AM)", pm: "Tarde (PM)" } as const;

/**
 * La hoja de cuadre: el cierre de caja de un turno (CAJ-11, docs/16).
 *
 * Es la hoja que la panadería ya llevaba a mano, con dos diferencias. La
 * primera es que suma sola. La segunda, y la que de verdad importa: al lado de
 * cada cifra escrita aparece **lo que el sistema facturó en ese turno**, con su
 * diferencia. Si no cuadra se ve en el momento, no tres días después.
 *
 * Los campos se siguen escribiendo a mano a propósito. Rellenarlos con lo que
 * el sistema ya sabe ahorraría un minuto y destruiría el control: si alguien no
 * factura una venta, nadie lo notaría nunca.
 */
export function HojaDeCuadre({
  id,
  puedeCuadrar,
  fechaHoy,
  turnoActual,
  alCerrar,
  alGuardar,
}: {
  id: string | null;
  puedeCuadrar: boolean;
  /** El día y el turno de la empresa: una hoja nueva abre en el turno que se acaba de trabajar. */
  fechaHoy: string;
  turnoActual: string;
  alCerrar: () => void;
  alGuardar: () => void | Promise<void>;
}) {
  const [fecha, setFecha] = useState(fechaHoy);
  const [turno, setTurno] = useState(turnoActual);
  const [inicial, setInicial] = useState("");
  const [lectura, setLectura] = useState("");
  const [cambio, setCambio] = useState("");
  const [tarjeta, setTarjeta] = useState("");
  const [athMovil, setAthMovil] = useState("");
  const [notas, setNotas] = useState("");
  const [gastos, setGastos] = useState<Gasto[]>([gastoVacio()]);

  const [hojaId, setHojaId] = useState<string | null>(id);
  const [facturado, setFacturado] = useState<FacturadoEnTurno | null>(null);
  const [cargando, setCargando] = useState(true);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState<ErrorApi | null>(null);
  const avisar = useAvisar();

  const rellenar = useCallback((h: CuadreDetalle) => {
    setHojaId(h.id);
    setFecha(h.fecha);
    setTurno(h.turno);
    setInicial(h.efectivo_inicial);
    setLectura(h.ventas_lectura);
    setCambio(h.efectivo_cambio);
    setTarjeta(h.tarjeta);
    setAthMovil(h.ath_movil);
    setNotas(h.notas ?? "");
    setGastos(
      h.gastos_detalle.length > 0
        ? h.gastos_detalle.map((g) => ({ ...g, clave: siguienteClave++ }))
        : [gastoVacio()],
    );
  }, []);

  useEffect(() => {
    let vigente = true;

    void (async () => {
      try {
        if (id) {
          const h = await api.get<CuadreDetalle>(`/cuadres/${id}`);
          if (vigente) rellenar(h);
        }
      } catch (e) {
        if (vigente) setError(e as ErrorApi);
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    return () => {
      vigente = false;
    };
  }, [id, rellenar]);

  // Lo que el sistema facturó en ese turno. Se vuelve a pedir cada vez que
  // cambian la fecha o el turno, que es lo que define la ventana.
  useEffect(() => {
    let vigente = true;

    void api
      .get<FacturadoEnTurno>(`/cuadres/facturado?fecha=${fecha}&turno=${turno}`)
      .then((f) => vigente && setFacturado(f))
      .catch(() => vigente && setFacturado(null));

    return () => {
      vigente = false;
    };
  }, [fecha, turno]);

  const totales = useMemo(() => {
    const ventaYCambio = aCentavos(inicial) + aCentavos(lectura);
    const totalEfectivo = ventaYCambio - aCentavos(tarjeta) - aCentavos(athMovil) - aCentavos(cambio);
    const totalGastos = gastos.reduce((suma, g) => suma + aCentavos(g.monto), 0);

    return {
      ventaYCambio,
      totalEfectivo,
      totalGastos,
      aDepositar: totalEfectivo - totalGastos,
      totalVentas: aCentavos(lectura),
    };
  }, [inicial, lectura, cambio, tarjeta, athMovil, gastos]);

  async function guardar() {
    setGuardando(true);
    setError(null);

    const cuerpo = {
      fecha,
      turno,
      efectivo_inicial: inicial || "0",
      ventas_lectura: lectura || "0",
      efectivo_cambio: cambio || "0",
      tarjeta: tarjeta || "0",
      ath_movil: athMovil || "0",
      notas: notas || null,
      gastos: gastos
        .filter((g) => g.descripcion.trim() && aCentavos(g.monto) > 0)
        .map((g) => ({ descripcion: g.descripcion, monto: g.monto })),
    };

    try {
      const h = hojaId
        ? await api.put<CuadreDetalle>(`/cuadres/${hojaId}`, cuerpo)
        : await api.post<CuadreDetalle>("/cuadres", cuerpo);

      // La respuesta del servidor pisa lo que hay en pantalla: él es quien
      // manda sobre los números.
      rellenar(h);
      avisar(`Hoja del ${h.fecha} ${h.turno.toUpperCase()} guardada. A depositar ${h.a_depositar}.`);
      await alGuardar();
    } catch (e) {
      setError(e as ErrorApi);
    } finally {
      setGuardando(false);
    }
  }

  function cambiarGasto(clave: number, campo: "descripcion" | "monto", valor: string) {
    setGastos((actuales) => actuales.map((g) => (g.clave === clave ? { ...g, [campo]: valor } : g)));
  }

  if (cargando) return <p className="vacio">Cargando la hoja...</p>;

  /** Lo que el sistema facturó frente a lo escrito, si difieren. */
  function contraste(escrito: string, delSistema: string | undefined) {
    if (!delSistema) return null;

    const diferencia = aCentavos(escrito) - aCentavos(delSistema);

    return (
      <small className="contraste" data-cuadra={diferencia === 0}>
        El sistema facturó {delSistema}
        {diferencia !== 0 && <b> · {diferencia > 0 ? "+" : "−"}{aTexto(Math.abs(diferencia))}</b>}
      </small>
    );
  }

  const soloLectura = !puedeCuadrar;

  return (
    <section className="cuadre-pantalla">
      <div className="hoja-barra">
        <div className="hoja-barra__titulo">
          <h1>{hojaId ? "Hoja de cuadre" : "Nueva hoja de cuadre"}</h1>
          <span className="etiqueta" data-tono="marca">{TURNO[turno as keyof typeof TURNO]}</span>
        </div>

        <div className="hoja-barra__acciones">
          <Boton variante="fantasma" type="button" onClick={alCerrar}>Cerrar</Boton>
          {/* Una hoja sin guardar no tiene papel: el PDF lo arma el servidor a
              partir de lo que hay en la base, no de lo que hay en pantalla. */}
          <Boton
            variante="suave"
            type="button"
            disabled={!hojaId}
            title={hojaId ? "Descargar la hoja en PDF" : "Guarda la hoja para poder imprimirla"}
            onClick={() => void descargar(`/cuadres/${hojaId}/pdf`)}
          >
            Imprimir PDF
          </Boton>
          {puedeCuadrar && (
            <Boton type="button" cargando={guardando} onClick={() => void guardar()}>
              Guardar datos
            </Boton>
          )}
        </div>
      </div>

      <article className="cuadre">
        {error && <Aviso>{error.message}</Aviso>}

        <div className="cuadre__rejilla">
          {/* --- lo que se escribe ------------------------------------------ */}
          <div className="cuadre__columna">
            <div className="campo">
              <label htmlFor="cuadre-fecha">Fecha</label>
              <input
                id="cuadre-fecha"
                type="date"
                value={fecha}
                max={fechaHoy}
                disabled={soloLectura}
                onChange={(e) => setFecha(e.target.value)}
              />
              {error?.campo("fecha") && <span className="error">{error.campo("fecha")}</span>}
            </div>

            <div className="campo">
              <label htmlFor="efectivo_inicial">Efectivo al comienzo</label>
              <input
                id="efectivo_inicial"
                inputMode="decimal"
                placeholder="0.00"
                value={inicial}
                disabled={soloLectura}
                onChange={(e) => setInicial(e.target.value)}
              />
              <small className="ayuda">El fondo de cambio con el que se abrió. No es una venta.</small>
            </div>

            <div className="campo">
              <label htmlFor="ventas_lectura">Ventas (según lectura)</label>
              <input
                id="ventas_lectura"
                inputMode="decimal"
                placeholder="0.00"
                value={lectura}
                disabled={soloLectura}
                onChange={(e) => setLectura(e.target.value)}
              />
              {contraste(lectura, facturado?.ventas)}
            </div>

            <div className="cuadre__calculado">
              <span>Total venta y cambio</span>
              <b className="numerica" id="venta-y-cambio">{aTexto(totales.ventaYCambio)}</b>
            </div>

            <div className="campo">
              <label htmlFor="efectivo_cambio">Efectivo para cambio</label>
              <input
                id="efectivo_cambio"
                inputMode="decimal"
                placeholder="0.00"
                value={cambio}
                disabled={soloLectura}
                onChange={(e) => setCambio(e.target.value)}
              />
              <small className="ayuda">Lo que se aparta para abrir el próximo turno.</small>
            </div>

            <div className="cuadre__calculado" data-fuerte="true">
              <span>Total efectivo</span>
              <b className="numerica" id="total-efectivo">{aTexto(totales.totalEfectivo)}</b>
            </div>
          </div>

          <div className="cuadre__columna">
            <div className="campo">
              <label htmlFor="cuadre-turno">Horario</label>
              <select
                id="cuadre-turno"
                value={turno}
                disabled={soloLectura}
                onChange={(e) => setTurno(e.target.value)}
              >
                <option value="am">Mañana (AM)</option>
                <option value="pm">Tarde (PM)</option>
              </select>
              {error?.campo("turno") && <span className="error">{error.campo("turno")}</span>}
            </div>

            <div className="campo">
              <label htmlFor="tarjeta">ATH / Visa / Mastercard</label>
              <input
                id="tarjeta"
                inputMode="decimal"
                placeholder="0.00"
                value={tarjeta}
                disabled={soloLectura}
                onChange={(e) => setTarjeta(e.target.value)}
              />
              {contraste(tarjeta, facturado?.tarjeta)}
            </div>

            <div className="campo">
              <label htmlFor="ath_movil">ATH Móvil</label>
              <input
                id="ath_movil"
                inputMode="decimal"
                placeholder="0.00"
                value={athMovil}
                disabled={soloLectura}
                onChange={(e) => setAthMovil(e.target.value)}
              />
              {contraste(athMovil, facturado?.ath_movil)}
            </div>

            {facturado && (
              <div className="cuadre__nota-sistema" id="ventana-turno">
                <b>{facturado.facturas} factura(s)</b> emitidas entre las {facturado.desde} y las{" "}
                {facturado.hasta}, por {facturado.ventas}.
              </div>
            )}
          </div>
        </div>

        {/* --- compras y gastos --------------------------------------------- */}
        <section className="cuadre__gastos">
          <h2>Compras y gastos</h2>

          <table className="renglones-hoja">
            <thead>
              <tr>
                <th>Descripción</th>
                <th className="derecha" style={{ width: "8rem" }}>Valor</th>
                {puedeCuadrar && <th style={{ width: "2rem" }} />}
              </tr>
            </thead>
            <tbody>
              {gastos.map((g, i) => (
                <tr key={g.clave}>
                  <td>
                    <input
                      id={`gasto-descripcion-${i}`}
                      aria-label={`Descripción del gasto ${i + 1}`}
                      placeholder="Nombre del gasto"
                      value={g.descripcion}
                      disabled={soloLectura}
                      onChange={(e) => cambiarGasto(g.clave, "descripcion", e.target.value)}
                    />
                  </td>
                  <td className="derecha">
                    <input
                      id={`gasto-monto-${i}`}
                      aria-label={`Valor del gasto ${i + 1}`}
                      inputMode="decimal"
                      placeholder="0.00"
                      value={g.monto}
                      disabled={soloLectura}
                      onChange={(e) => cambiarGasto(g.clave, "monto", e.target.value)}
                    />
                  </td>
                  {puedeCuadrar && (
                    <td>
                      <button
                        type="button"
                        className="renglon-hoja__quitar"
                        aria-label={`Quitar el gasto ${i + 1}`}
                        onClick={() =>
                          setGastos((a) => (a.length > 1 ? a.filter((x) => x.clave !== g.clave) : [gastoVacio()]))
                        }
                      >
                        ×
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>

          {puedeCuadrar && (
            <button type="button" className="hoja__agregar" onClick={() => setGastos((a) => [...a, gastoVacio()])}>
              + Agregar gasto
            </button>
          )}
        </section>

        {/* --- el total de la hoja ------------------------------------------- */}
        <section className="cuadre__cierre">
          <div className="campo">
            <label htmlFor="cuadre-notas">Notas</label>
            <textarea
              id="cuadre-notas"
              rows={3}
              value={notas}
              disabled={soloLectura}
              placeholder="Lo que haya que explicar de este turno"
              onChange={(e) => setNotas(e.target.value)}
            />
          </div>

          <dl className="totales-hoja" id="totales-cuadre">
            <div className="totales-hoja__linea">
              <dt>Total de compras y gastos</dt>
              {/* Un cero no lleva signo: "−0.00" se lee como un descuido. */}
              <dd>{totales.totalGastos > 0 ? "−" : ""}{aTexto(totales.totalGastos)}</dd>
            </div>
            <div className="totales-hoja__linea" data-total="true" data-alerta={totales.aDepositar < 0}>
              <dt>Efectivo para depositar</dt>
              <dd>{aTexto(totales.aDepositar)}</dd>
            </div>
            <div className="totales-hoja__linea">
              <dt>Total ventas</dt>
              <dd>{aTexto(totales.totalVentas)}</dd>
            </div>
          </dl>
        </section>

        {totales.aDepositar < 0 && (
          <Aviso tono="aviso">
            El depósito sale negativo: se gastó más efectivo del que quedó en la gaveta. Revisa las
            cifras antes de guardar.
          </Aviso>
        )}
      </article>
    </section>
  );
}
