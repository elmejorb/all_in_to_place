import { useState } from "react";
import { Aviso, Boton, Campo, Dialogo } from "@aiop/ui";
import { api, ErrorApi, type Cliente, type Producto } from "../api";

/**
 * Dar de alta un cliente o un producto sin salir de la factura.
 *
 * El caso es real y frecuente: llega alguien que nunca ha comprado, o hay que
 * cobrar algo que no está en el catálogo. Obligar a abandonar la factura a
 * medias para ir a otra pantalla es perder el hilo y, a veces, perder la venta.
 *
 * Por eso los dos formularios piden **lo mínimo** para poder facturar, que es
 * lo mismo que exige el servidor: un nombre. Todo lo demás —identificación,
 * crédito, exención, categoría, costos— se completa después en su pantalla, con
 * calma. Repetir aquí la ficha entera convertiría un atajo en otro formulario.
 */

/** El valor que marca la opción "crear" al final de un desplegable. */
export const CREAR = "__crear__";

export function CrearCliente({
  alCrear,
  alCerrar,
}: {
  alCrear: (cliente: Cliente) => void;
  alCerrar: () => void;
}) {
  const [nombre, setNombre] = useState("");
  const [telefono, setTelefono] = useState("");
  const [email, setEmail] = useState("");
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState<ErrorApi | null>(null);

  async function guardar(evento: React.FormEvent) {
    evento.preventDefault();
    setGuardando(true);
    setError(null);

    try {
      alCrear(await api.post<Cliente>("/clientes", {
        nombre,
        telefono: telefono || null,
        email: email || null,
      }));
    } catch (e) {
      setError(e as ErrorApi);
    } finally {
      setGuardando(false);
    }
  }

  const general = error && !error.campo("nombre") && !error.campo("telefono") && !error.campo("email");

  return (
    <Dialogo
      titulo="Cliente nuevo"
      descripcion="Con el nombre basta; lo demás se completa luego en Clientes."
      alCerrar={alCerrar}
      pie={
        <>
          <Boton variante="suave" type="button" onClick={alCerrar}>Cancelar</Boton>
          <Boton type="submit" form="forma-cliente-vuelo" cargando={guardando} disabled={nombre.trim().length < 2}>
            Crear y usarlo
          </Boton>
        </>
      }
    >
      {general && <Aviso>{error?.message}</Aviso>}

      <form id="forma-cliente-vuelo" onSubmit={guardar} noValidate>
        <Campo
          id="vuelo-cliente-nombre"
          etiqueta="Nombre"
          required
          autoComplete="off"
          value={nombre}
          error={error?.campo("nombre")}
          onChange={(e) => {
            setNombre(e.target.value);
            setError((a) => a?.sinCampo("nombre") ?? null);
          }}
        />
        <div className="dialogo__par">
          <Campo
            id="vuelo-cliente-telefono"
            etiqueta="Teléfono"
            inputMode="tel"
            value={telefono}
            error={error?.campo("telefono")}
            onChange={(e) => {
              setTelefono(e.target.value);
              setError((a) => a?.sinCampo("telefono") ?? null);
            }}
          />
          <Campo
            id="vuelo-cliente-email"
            etiqueta="Correo"
            type="email"
            value={email}
            error={error?.campo("email")}
            onChange={(e) => {
              setEmail(e.target.value);
              setError((a) => a?.sinCampo("email") ?? null);
            }}
          />
        </div>
      </form>
    </Dialogo>
  );
}

export function CrearProducto({
  impuestoPorDefecto,
  unidades,
  alCrear,
  alCerrar,
}: {
  impuestoPorDefecto: string;
  unidades: string[];
  alCrear: (producto: Producto) => void;
  alCerrar: () => void;
}) {
  const [nombre, setNombre] = useState("");
  const [precio, setPrecio] = useState("");
  const [unidad, setUnidad] = useState(unidades[0] ?? "unidad");
  const [impuesto, setImpuesto] = useState(impuestoPorDefecto);
  const [esServicio, setEsServicio] = useState(false);
  const [existencia, setExistencia] = useState("");
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState<ErrorApi | null>(null);

  async function guardar(evento: React.FormEvent) {
    evento.preventDefault();
    setGuardando(true);
    setError(null);

    try {
      alCrear(await api.post<Producto>("/productos", {
        nombre,
        precio: precio || null,
        unidad,
        impuesto,
        es_servicio: esServicio,
        // Un servicio no lleva existencia: ni se pide ni se acepta (PRO-03).
        existencia_inicial: esServicio || !existencia ? null : existencia,
      }));
    } catch (e) {
      setError(e as ErrorApi);
    } finally {
      setGuardando(false);
    }
  }

  const campos = ["nombre", "precio", "unidad", "impuesto", "existencia_inicial"];
  const general = error && !campos.some((c) => error.campo(c));

  return (
    <Dialogo
      titulo="Producto nuevo"
      descripcion="Lo justo para poder venderlo; la ficha completa se edita en Productos."
      alCerrar={alCerrar}
      pie={
        <>
          <Boton variante="suave" type="button" onClick={alCerrar}>Cancelar</Boton>
          <Boton type="submit" form="forma-producto-vuelo" cargando={guardando} disabled={nombre.trim().length < 2}>
            Crear y usarlo
          </Boton>
        </>
      }
    >
      {general && <Aviso>{error?.message}</Aviso>}

      <form id="forma-producto-vuelo" onSubmit={guardar} noValidate>
        <Campo
          id="vuelo-producto-nombre"
          etiqueta="Nombre"
          required
          autoComplete="off"
          value={nombre}
          error={error?.campo("nombre")}
          onChange={(e) => {
            setNombre(e.target.value);
            setError((a) => a?.sinCampo("nombre") ?? null);
          }}
        />

        <div className="dialogo__par">
          <Campo
            id="vuelo-producto-precio"
            etiqueta="Precio de venta"
            inputMode="decimal"
            placeholder="0.00"
            value={precio}
            error={error?.campo("precio")}
            onChange={(e) => {
              setPrecio(e.target.value);
              setError((a) => a?.sinCampo("precio") ?? null);
            }}
          />
          <Campo
            id="vuelo-producto-impuesto"
            etiqueta="Impuesto %"
            inputMode="decimal"
            value={impuesto}
            error={error?.campo("impuesto")}
            onChange={(e) => {
              setImpuesto(e.target.value);
              setError((a) => a?.sinCampo("impuesto") ?? null);
            }}
          />
        </div>

        <div className="dialogo__par">
          <div className="campo">
            <label htmlFor="vuelo-producto-unidad">Unidad</label>
            <select id="vuelo-producto-unidad" value={unidad} onChange={(e) => setUnidad(e.target.value)}>
              {unidades.map((u) => (
                <option key={u} value={u}>{u}</option>
              ))}
            </select>
          </div>

          {!esServicio && (
            <Campo
              id="vuelo-producto-existencia"
              etiqueta="Existencia"
              inputMode="decimal"
              placeholder="0"
              value={existencia}
              error={error?.campo("existencia_inicial")}
              onChange={(e) => {
                setExistencia(e.target.value);
                setError((a) => a?.sinCampo("existencia_inicial") ?? null);
              }}
            />
          )}
        </div>

        <label className="interruptor" htmlFor="vuelo-producto-servicio">
          <input
            id="vuelo-producto-servicio"
            type="checkbox"
            checked={esServicio}
            onChange={(e) => setEsServicio(e.target.checked)}
          />
          Es un servicio, no lleva existencia
        </label>
      </form>
    </Dialogo>
  );
}
