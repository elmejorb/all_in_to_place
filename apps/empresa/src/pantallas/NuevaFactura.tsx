import { useCallback, useEffect, useMemo, useState } from "react";
import { Aviso, Boton, Campo, PanelLateral } from "@aiop/ui";
import {
  api,
  ErrorApi,
  type Calculo,
  type Cliente,
  type FacturaDetalle,
  type ListadoClientes,
  type ListadoProductos,
  type Producto,
} from "../api";
import { CREAR, CrearCliente, CrearProducto } from "./CrearAlVuelo";

type Renglon = {
  clave: number;
  producto: string;
  descripcion: string;
  cantidad: string;
  precio: string;
};

let siguienteClave = 1;

/**
 * La clave es para React; los identificadores del DOM van por posición, que es
 * lo estable: el contador se duplica en desarrollo por el modo estricto.
 */
function renglonVacio(): Renglon {
  return { clave: siguienteClave++, producto: "", descripcion: "", cantidad: "1", precio: "" };
}

/**
 * Armar una venta: elegir cliente, agregar renglones y cobrar (FAC-01, FAC-07).
 *
 * Los totales los calcula el servidor mientras se escribe: el mismo código que
 * emite es el que muestra, así que lo que se ve es lo que se va a cobrar.
 */
export function NuevaFactura({
  metodosPago,
  alCerrar,
  alEmitir,
}: {
  metodosPago: string[];
  alCerrar: () => void;
  alEmitir: (factura: FacturaDetalle) => Promise<void>;
}) {
  const [clientes, setClientes] = useState<Cliente[]>([]);
  const [productos, setProductos] = useState<Producto[]>([]);
  const [cliente, setCliente] = useState("");
  const [renglones, setRenglones] = useState<Renglon[]>([renglonVacio()]);
  const [descuentoTipo, setDescuentoTipo] = useState("porcentaje");
  const [descuentoValor, setDescuentoValor] = useState("");
  const [metodo, setMetodo] = useState(metodosPago[0] ?? "efectivo");
  const [recibido, setRecibido] = useState("");
  const [cobrarAhora, setCobrarAhora] = useState(true);
  const [calculo, setCalculo] = useState<Calculo | null>(null);
  const [emitiendo, setEmitiendo] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [faltantes, setFaltantes] = useState<{ producto: string; pedido: number; disponible: number }[]>([]);
  const [unidades, setUnidades] = useState<string[]>([]);
  const [tasaEmpresa, setTasaEmpresa] = useState("");
  const [puedeCrear, setPuedeCrear] = useState({ cliente: false, producto: false });
  /** Qué se está dando de alta sin salir del mostrador. */
  const [creando, setCreando] = useState<{ que: "cliente" } | { que: "producto"; clave: number } | null>(null);

  useEffect(() => {
    let vigente = true;

    void (async () => {
      const [c, p, m] = await Promise.all([
        api.get<ListadoClientes>("/clientes?por_pagina=100"),
        api.get<ListadoProductos>("/productos?por_pagina=100"),
        api.get<{ impuesto_tasa: string }>("/empresa"),
      ]);
      if (!vigente) return;

      setClientes(c.datos);
      setProductos(p.datos);
      setUnidades(p.unidades);
      setTasaEmpresa(m.impuesto_tasa);
      setPuedeCrear({ cliente: c.permisos.editar, producto: p.permisos.editar });
    })().catch(() => undefined);

    return () => {
      vigente = false;
    };
  }, []);

  const listos = useMemo(
    () => renglones.filter((r) => (r.producto || r.descripcion.trim()) && Number(r.cantidad) > 0),
    [renglones],
  );

  const cuerpo = useCallback(
    () => ({
      cliente: cliente || null,
      descuento_tipo: descuentoValor ? descuentoTipo : null,
      descuento_valor: descuentoValor || null,
      renglones: listos.map((r) => ({
        producto: r.producto || null,
        descripcion: r.producto ? null : r.descripcion,
        cantidad: r.cantidad,
        precio: r.precio || null,
      })),
    }),
    [cliente, descuentoTipo, descuentoValor, listos],
  );

  // Totales en vivo, calculados por el mismo código que emite.
  useEffect(() => {
    if (listos.length === 0) {
      setCalculo(null);
      return;
    }

    const t = setTimeout(() => {
      void api
        .post<Calculo>("/facturas/calcular", cuerpo())
        .then(setCalculo)
        .catch(() => undefined);
    }, 250);

    return () => clearTimeout(t);
  }, [cuerpo, listos.length]);

  function cambiar(clave: number, campo: keyof Renglon, valor: string) {
    setRenglones((actuales) =>
      actuales.map((r) => {
        if (r.clave !== clave) return r;

        const nuevo = { ...r, [campo]: valor };

        // Al elegir producto se trae su precio, pero se puede cambiar.
        if (campo === "producto") {
          const p = productos.find((x) => x.id === valor);
          nuevo.precio = p ? p.precio : "";
          nuevo.descripcion = p?.nombre ?? "";
        }

        return nuevo;
      }),
    );
  }

  async function emitir(permitirSinExistencia = false) {
    setEmitiendo(true);
    setError(null);
    setFaltantes([]);

    const pagos =
      cobrarAhora && calculo
        // Sin monto: el servidor cobra el saldo. Mandar el total calculado aquí
        // falla si el cálculo viene a medio refrescar.
        ? [{ metodo, monto: null, recibido: metodo === "efectivo" && recibido ? recibido : null }]
        : [];

    try {
      const factura = await api.post<FacturaDetalle>("/facturas", {
        ...cuerpo(),
        pagos,
        permitir_sin_existencia: permitirSinExistencia,
      });
      await alEmitir(factura);
    } catch (e) {
      const fallo = e as ErrorApi;

      if (fallo.codigo === "sin_existencia") {
        // No es un error: es una pregunta para quien está en la caja (FAC-08).
        setFaltantes((fallo.cuerpo.faltantes as typeof faltantes) ?? []);
        setError(null);
      } else {
        setError(fallo.message);
      }
    } finally {
      setEmitiendo(false);
    }
  }

  const cambio =
    metodo === "efectivo" && recibido && calculo
      ? Math.round((Number(recibido.replace(",", ".")) - Number(calculo.total)) * 100) / 100
      : null;

  return (
    <PanelLateral
      abierto
      titulo="Nueva factura"
      descripcion={listos.length === 0 ? "Agrega lo que se lleva el cliente" : `${listos.length} renglón(es)`}
      haycambios={listos.length > 0}
      alCerrar={alCerrar}
      pie={
        <>
          <Boton variante="suave" type="button" onClick={alCerrar}>Cancelar</Boton>
          <Boton
            type="button"
            cargando={emitiendo}
            disabled={listos.length === 0}
            onClick={() => void emitir()}
          >
            {calculo ? `Emitir por ${calculo.total}` : "Emitir"}
          </Boton>
        </>
      }
    >
      {error && <Aviso>{error}</Aviso>}

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
          <Boton variante="suave" type="button" onClick={() => void emitir(true)} cargando={emitiendo}>
            Vender igual
          </Boton>
        </div>
      )}

      <div className="campo">
        <label htmlFor="cliente">Cliente</label>
        <select
          id="cliente"
          value={cliente}
          onChange={(e) =>
            e.target.value === CREAR ? setCreando({ que: "cliente" }) : setCliente(e.target.value)
          }
        >
          <option value="">Sin cliente (venta de mostrador)</option>
          {clientes.map((c) => (
            <option key={c.id} value={c.id}>
              {c.nombre}{c.exento ? " (exento)" : ""}
            </option>
          ))}
          {puedeCrear.cliente && <option value={CREAR}>+ Crear un cliente...</option>}
        </select>
      </div>

      <div className="renglones">
        {renglones.map((r, i) => (
          <div className="renglon" key={r.clave}>
            <div className="campo renglon__producto">
              <label htmlFor={`producto-${i}`}>Producto</label>
              <select
                id={`producto-${i}`}
                value={r.producto}
                onChange={(e) =>
                  e.target.value === CREAR
                    ? setCreando({ que: "producto", clave: r.clave })
                    : cambiar(r.clave, "producto", e.target.value)
                }
              >
                <option value="">Texto libre</option>
                {productos.map((p) => (
                  <option key={p.id} value={p.id}>{p.nombre}</option>
                ))}
                {puedeCrear.producto && <option value={CREAR}>+ Crear un producto...</option>}
              </select>
            </div>

            {!r.producto && (
              <Campo
                id={`descripcion-${i}`}
                etiqueta="Concepto"
                value={r.descripcion}
                onChange={(e) => cambiar(r.clave, "descripcion", e.target.value)}
              />
            )}

            <div className="renglon__cifras">
              <Campo
                id={`cantidad-${i}`}
                etiqueta="Cantidad"
                inputMode="decimal"
                value={r.cantidad}
                onChange={(e) => cambiar(r.clave, "cantidad", e.target.value)}
              />
              <Campo
                id={`precio-${i}`}
                etiqueta="Precio"
                inputMode="decimal"
                value={r.precio}
                onChange={(e) => cambiar(r.clave, "precio", e.target.value)}
              />
              {renglones.length > 1 && (
                <button
                  type="button"
                  className="boton-fila renglon__quitar"
                  aria-label="Quitar este renglón"
                  onClick={() => setRenglones((a) => a.filter((x) => x.clave !== r.clave))}
                >
                  Quitar
                </button>
              )}
            </div>
          </div>
        ))}

        <Boton variante="suave" type="button" onClick={() => setRenglones((a) => [...a, renglonVacio()])}>
          Agregar renglón
        </Boton>
      </div>

      <div className="fila-campos">
        <div className="campo">
          <label htmlFor="descuento_tipo">Descuento</label>
          <select id="descuento_tipo" value={descuentoTipo} onChange={(e) => setDescuentoTipo(e.target.value)}>
            <option value="porcentaje">Porcentaje</option>
            <option value="monto">Monto</option>
          </select>
        </div>
        <Campo
          id="descuento_valor"
          etiqueta="Valor"
          inputMode="decimal"
          value={descuentoValor}
          onChange={(e) => setDescuentoValor(e.target.value)}
        />
      </div>

      {calculo && (
        <dl className="totales" id="totales-venta">
          <div><dt>Subtotal</dt><dd className="numerica">{calculo.subtotal}</dd></div>
          {Number(calculo.descuento) > 0 && (
            <div><dt>Descuento</dt><dd className="numerica">−{calculo.descuento}</dd></div>
          )}
          {calculo.desglose.map((d) => (
            <div key={d.nombre}><dt>{d.nombre}</dt><dd className="numerica">{d.monto}</dd></div>
          ))}
          <div data-total="true"><dt>Total</dt><dd className="numerica">{calculo.total}</dd></div>
        </dl>
      )}

      <label className="interruptor">
        <input id="cobrar_ahora" type="checkbox" checked={cobrarAhora} onChange={(e) => setCobrarAhora(e.target.checked)} />
        <span>
          Cobrar ahora
          <small>Sin marcar, la factura queda pendiente de cobro.</small>
        </span>
      </label>

      {cobrarAhora && (
        <div className="cobro">
          <div className="campo">
            <label htmlFor="metodo_pago">Método de pago</label>
            <select id="metodo_pago" value={metodo} onChange={(e) => setMetodo(e.target.value)}>
              {metodosPago.map((m) => (
                <option key={m} value={m}>{m.replace("_", " ")}</option>
              ))}
            </select>
          </div>

          {metodo === "efectivo" && (
            <>
              <Campo
                id="recibido"
                etiqueta="Con cuánto paga"
                inputMode="decimal"
                value={recibido}
                onChange={(e) => setRecibido(e.target.value)}
              />
              {cambio !== null && (
                <p className="margen-vivo" data-alerta={cambio < 0 ? "true" : undefined} id="cambio-vivo">
                  {cambio < 0
                    ? `Faltan ${Math.abs(cambio).toFixed(2)}`
                    : `Cambio: ${cambio.toFixed(2)}`}
                </p>
              )}
            </>
          )}
        </div>
      )}
      {creando?.que === "cliente" && (
        <CrearCliente
          alCerrar={() => setCreando(null)}
          alCrear={(nuevo) => {
            setClientes((actuales) => [...actuales, nuevo].sort((a, b) => a.nombre.localeCompare(b.nombre)));
            setCliente(nuevo.id);
            setCreando(null);
          }}
        />
      )}

      {creando?.que === "producto" && (
        <CrearProducto
          impuestoPorDefecto={tasaEmpresa}
          unidades={unidades}
          alCerrar={() => setCreando(null)}
          alCrear={(nuevo) => {
            setProductos((actuales) => [...actuales, nuevo].sort((a, b) => a.nombre.localeCompare(b.nombre)));

            // Con los datos del producto recién creado: en este instante la
            // lista todavía es la de antes y no lo encontraría.
            setRenglones((actuales) =>
              actuales.map((r) =>
                r.clave === creando.clave
                  ? { ...r, producto: nuevo.id, descripcion: nuevo.nombre, precio: nuevo.precio }
                  : r,
              ),
            );
            setCreando(null);
          }}
        />
      )}
    </PanelLateral>
  );
}
