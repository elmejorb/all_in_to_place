import { useCallback, useEffect, useState } from "react";
import { Aviso, Boton, Campo, PanelLateral, useAvisar } from "@aiop/ui";
import { api, ErrorApi, type Cliente, type ListadoClientes } from "../api";

const VACIO: Cliente = {
  id: "",
  nombre: "",
  tipo: "persona",
  identificacion: null,
  telefono: null,
  email: null,
  direccion: null,
  exento: false,
  certificado_exencion: null,
  terminos_pago: null,
  limite_credito: "0.00",
  tiene_credito: false,
  notas: null,
  activo: true,
};

export function Clientes({ soloLectura }: { soloLectura: boolean }) {
  const [listado, setListado] = useState<ListadoClientes | null>(null);
  const [buscar, setBuscar] = useState("");
  const [tipo, setTipo] = useState("");
  const [estado, setEstado] = useState("activos");
  const [conCredito, setConCredito] = useState(false);
  const [cursor, setCursor] = useState<string | null>(null);
  const [cargando, setCargando] = useState(true);
  const [editando, setEditando] = useState<Cliente | null>(null);
  const [fallo, setFallo] = useState<string | null>(null);
  const avisar = useAvisar();

  const cargar = useCallback(async () => {
    setCargando(true);
    setFallo(null);
    const p = new URLSearchParams({ estado });
    if (buscar.trim()) p.set("buscar", buscar.trim());
    if (tipo) p.set("tipo", tipo);
    if (conCredito) p.set("con_credito", "1");
    if (cursor) p.set("cursor", cursor);

    try {
      setListado(await api.get<ListadoClientes>(`/clientes?${p}`));
    } catch (e) {
      setFallo((e as ErrorApi).message);
    } finally {
      setCargando(false);
    }
  }, [buscar, tipo, estado, conCredito, cursor]);

  useEffect(() => {
    const t = setTimeout(() => void cargar(), buscar ? 300 : 0);
    return () => clearTimeout(t);
  }, [cargar, buscar]);

  function filtrar(accion: () => void) {
    setCursor(null);
    accion();
  }

  function limpiar() {
    setCursor(null);
    setBuscar("");
    setTipo("");
    setEstado("activos");
    setConCredito(false);
  }

  async function cambiarActivo(cliente: Cliente) {
    const accion = cliente.activo ? "desactivar" : "reactivar";
    if (cliente.activo && !window.confirm(`¿Desactivar a ${cliente.nombre}? Dejará de aparecer al facturar, pero conserva su historial.`)) {
      return;
    }
    await api.post(`/clientes/${cliente.id}/${accion}`);
    avisar(cliente.activo ? `${cliente.nombre} quedó inactivo.` : `${cliente.nombre} está activo otra vez.`);
    await cargar();
  }

  const datos = listado?.datos ?? [];
  const permisos = listado?.permisos ?? { editar: false, desactivar: false };
  const puedeEditar = permisos.editar && !soloLectura;
  const hayFiltro = Boolean(buscar.trim() || tipo || conCredito || estado !== "activos");

  return (
    <section className="pantalla">
      <div className="pantalla__cabeza">
        <div>
          <h1>Clientes</h1>
          <p>A quién le vendes, con sus términos, su crédito y su exención.</p>
        </div>
        {puedeEditar && (
          <div className="pantalla__acciones">
            <Boton onClick={() => setEditando({ ...VACIO })}>Nuevo cliente</Boton>
          </div>
        )}
      </div>

      <div className="filtros">
        <input
          id="buscar"
          type="search"
          placeholder="Buscar por nombre, identificación, teléfono o correo"
          aria-label="Buscar clientes"
          value={buscar}
          onChange={(e) => filtrar(() => setBuscar(e.target.value))}
        />

        <select id="tipo" aria-label="Tipo" value={tipo} onChange={(e) => filtrar(() => setTipo(e.target.value))}>
          <option value="">Personas y empresas</option>
          <option value="persona">Solo personas</option>
          <option value="empresa">Solo empresas</option>
        </select>

        <select id="estado" aria-label="Estado" value={estado} onChange={(e) => filtrar(() => setEstado(e.target.value))}>
          <option value="activos">Activos</option>
          <option value="inactivos">Inactivos</option>
          <option value="todos">Todos</option>
        </select>

        <label className="filtro-casilla">
          <input
            id="con_credito"
            type="checkbox"
            checked={conCredito}
            onChange={(e) => filtrar(() => setConCredito(e.target.checked))}
          />
          Solo con crédito
        </label>

        {hayFiltro && (
          <button type="button" className="boton-fila" onClick={limpiar}>Limpiar filtros</button>
        )}
      </div>

      {fallo && <Aviso>{fallo}</Aviso>}

      {cargando && !listado ? (
        <p className="vacio">Cargando...</p>
      ) : datos.length === 0 ? (
        <div className="vacio-caja">
          <h2>{hayFiltro ? "Ningún cliente coincide" : "Aún no tienes clientes"}</h2>
          <p>
            {hayFiltro
              ? "Prueba con otra búsqueda o quita algún filtro."
              : "Registra a quién le vendes para poder facturarle y llevarle cuenta."}
          </p>
          {hayFiltro ? (
            <Boton variante="suave" onClick={limpiar}>Limpiar filtros</Boton>
          ) : (
            puedeEditar && <Boton onClick={() => setEditando({ ...VACIO })}>Registrar el primero</Boton>
          )}
        </div>
      ) : (
        <>
          <div className="tabla-caja tarjeta">
            <table className="tabla">
              <thead>
                <tr>
                  <th>Cliente</th>
                  <th>Teléfono</th>
                  <th>Correo</th>
                  <th>Términos</th>
                  <th className="derecha">Crédito</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {datos.map((c) => (
                  <tr key={c.id} data-inactivo={!c.activo}>
                    <td>
                      <span className="celda-principal">
                        <b>{c.nombre}</b>
                        <small>
                          {c.tipo === "empresa" ? "Empresa" : "Persona"}
                          {c.identificacion ? ` · ${c.identificacion}` : ""}
                        </small>
                        <span className="etiquetas-fila">
                          {c.exento && <span className="etiqueta" data-tono="marca">Exento</span>}
                          {!c.activo && <span className="etiqueta">Inactivo</span>}
                        </span>
                      </span>
                    </td>
                    <td className="numerica" data-etiqueta="Teléfono">{c.telefono ?? "—"}</td>
                    <td data-etiqueta="Correo">{c.email ?? "—"}</td>
                    <td data-etiqueta="Términos">{c.terminos_pago ?? "—"}</td>
                    <td className="numerica derecha" data-etiqueta="Crédito">
                      {c.tiene_credito ? c.limite_credito : <span className="tenue">Sin crédito</span>}
                    </td>
                    <td className="celda-acciones">
                      <div className="acciones">
                        {puedeEditar && (
                          <button type="button" className="boton-fila" onClick={() => setEditando(c)}>
                            Editar
                          </button>
                        )}
                        {permisos.desactivar && !soloLectura && (
                          <button type="button" className="boton-fila" onClick={() => void cambiarActivo(c)}>
                            {c.activo ? "Desactivar" : "Reactivar"}
                          </button>
                        )}
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

      {editando && (
        <FormularioCliente
          cliente={editando}
          alCerrar={() => setEditando(null)}
          alGuardar={async (nombre) => {
            setEditando(null);
            setCursor(null);
            avisar(`${nombre} quedó guardado.`);
            await cargar();
          }}
        />
      )}
    </section>
  );
}

function FormularioCliente({
  cliente,
  alCerrar,
  alGuardar,
}: {
  cliente: Cliente;
  alCerrar: () => void;
  alGuardar: (nombre: string) => Promise<void>;
}) {
  const esNuevo = cliente.id === "";
  const [datos, setDatos] = useState({
    nombre: cliente.nombre,
    tipo: cliente.tipo,
    identificacion: cliente.identificacion ?? "",
    telefono: cliente.telefono ?? "",
    email: cliente.email ?? "",
    direccion: cliente.direccion ?? "",
    exento: cliente.exento,
    certificado_exencion: cliente.certificado_exencion ?? "",
    terminos_pago: cliente.terminos_pago ?? "",
    limite_credito: cliente.limite_credito,
    notas: cliente.notas ?? "",
  });
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorApi | null>(null);
  const [tocado, setTocado] = useState(false);

  function cambiar(campo: keyof typeof datos, valor: string | boolean) {
    setTocado(true);
    setDatos((d) => ({ ...d, [campo]: valor }));
    setError((actual) => actual?.sinCampo(campo as string) ?? null);
  }

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    setEnviando(true);
    setError(null);

    try {
      const cuerpo = { ...datos, certificado_exencion: datos.exento ? datos.certificado_exencion : null };
      if (esNuevo) await api.post("/clientes", cuerpo);
      else await api.put(`/clientes/${cliente.id}`, cuerpo);
      await alGuardar(datos.nombre);
    } catch (e) {
      setError(e as ErrorApi);
    } finally {
      setEnviando(false);
    }
  }

  const errorGeneral = error && Object.keys(error.errores).length === 0 ? error.message : null;

  return (
    <PanelLateral
      abierto
      titulo={esNuevo ? "Nuevo cliente" : "Editar cliente"}
      descripcion={esNuevo ? "Con el nombre basta; el resto lo completas cuando puedas." : cliente.nombre}
      haycambios={tocado}
      alCerrar={alCerrar}
      pie={
        <>
          <Boton variante="suave" type="button" onClick={alCerrar}>Cancelar</Boton>
          <Boton type="submit" form="formulario-cliente" cargando={enviando}>
            {enviando ? "Guardando..." : "Guardar"}
          </Boton>
        </>
      }
    >
      <form id="formulario-cliente" onSubmit={enviar} noValidate>
        {errorGeneral && <Aviso>{errorGeneral}</Aviso>}

        <Campo
          id="nombre"
          etiqueta="Nombre"
          required
          value={datos.nombre}
          error={error?.campo("nombre")}
          onChange={(e) => cambiar("nombre", e.target.value)}
        />

        <div className="campo">
          <label htmlFor="tipo_campo">Tipo</label>
          <select id="tipo_campo" value={datos.tipo} onChange={(e) => cambiar("tipo", e.target.value)}>
            <option value="persona">Persona</option>
            <option value="empresa">Empresa</option>
          </select>
        </div>

        <div className="fila-campos">
          <Campo
            id="telefono"
            etiqueta="Teléfono"
            type="tel"
            value={datos.telefono}
            error={error?.campo("telefono")}
            onChange={(e) => cambiar("telefono", e.target.value)}
          />
          <Campo
            id="identificacion"
            etiqueta={datos.tipo === "empresa" ? "Registro o EIN" : "Identificación"}
            value={datos.identificacion}
            error={error?.campo("identificacion")}
            onChange={(e) => cambiar("identificacion", e.target.value)}
          />
        </div>

        <Campo
          id="email"
          etiqueta="Correo"
          type="email"
          value={datos.email}
          error={error?.campo("email")}
          onChange={(e) => cambiar("email", e.target.value)}
        />

        <Campo
          id="direccion"
          etiqueta="Dirección"
          value={datos.direccion}
          error={error?.campo("direccion")}
          onChange={(e) => cambiar("direccion", e.target.value)}
        />

        <label className="interruptor">
          <input
            id="exento"
            type="checkbox"
            checked={datos.exento}
            onChange={(e) => cambiar("exento", e.target.checked)}
          />
          <span>
            Exento de impuesto
            <small>Hace falta el número del certificado: sin él la exención no se sostiene.</small>
          </span>
        </label>

        {datos.exento && (
          <Campo
            id="certificado_exencion"
            etiqueta="Número de certificado"
            required
            value={datos.certificado_exencion}
            error={error?.campo("certificado_exencion")}
            onChange={(e) => cambiar("certificado_exencion", e.target.value)}
          />
        )}

        <div className="fila-campos">
          <Campo
            id="terminos_pago"
            etiqueta="Términos de pago"
            placeholder="30 días, contado…"
            value={datos.terminos_pago}
            error={error?.campo("terminos_pago")}
            onChange={(e) => cambiar("terminos_pago", e.target.value)}
          />
          <Campo
            id="limite_credito"
            etiqueta="Límite de crédito"
            inputMode="decimal"
            value={datos.limite_credito}
            error={error?.campo("limite_credito")}
            onChange={(e) => cambiar("limite_credito", e.target.value)}
          />
        </div>

        <p className="vacio sin-margen" id="ayuda-credito">
          {Number(datos.limite_credito.replace(",", ".")) > 0
            ? "Podrá llevarse mercancía a crédito hasta ese monto."
            : "Con el límite en cero, este cliente paga de contado."}
        </p>

        <Campo
          id="notas"
          etiqueta="Notas"
          value={datos.notas}
          error={error?.campo("notas")}
          onChange={(e) => cambiar("notas", e.target.value)}
        />
      </form>
    </PanelLateral>
  );
}
