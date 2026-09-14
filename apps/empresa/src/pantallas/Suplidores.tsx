import { useCallback, useEffect, useState } from "react";
import { Aviso, Boton, Campo, PanelLateral } from "@aiop/ui";
import { api, ErrorApi, type Listado, type Suplidor } from "../api";

const VACIO: Suplidor = {
  id: "",
  razon_social: "",
  nombre_comercial: null,
  numero_cliente: null,
  telefono: null,
  email: null,
  vendedor: null,
  terminos_pago: null,
  notas: null,
  activo: true,
};

type Estado = "activos" | "inactivos" | "todos";

export function Suplidores({ soloLectura }: { soloLectura: boolean }) {
  const [listado, setListado] = useState<Listado<Suplidor> | null>(null);
  const [buscar, setBuscar] = useState("");
  const [estado, setEstado] = useState<Estado>("activos");
  const [orden, setOrden] = useState<"razon_social" | "numero_cliente" | "vendedor">("razon_social");
  const [direccion, setDireccion] = useState<"asc" | "desc">("asc");
  const [cursor, setCursor] = useState<string | null>(null);
  const [cargando, setCargando] = useState(true);
  const [editando, setEditando] = useState<Suplidor | null>(null);
  const [fallo, setFallo] = useState<string | null>(null);

  const cargar = useCallback(async () => {
    setCargando(true);
    setFallo(null);
    const parametros = new URLSearchParams({ estado, orden, direccion });
    if (buscar.trim()) parametros.set("buscar", buscar.trim());
    if (cursor) parametros.set("cursor", cursor);

    try {
      setListado(await api.get<Listado<Suplidor>>(`/suplidores?${parametros}`));
    } catch (e) {
      setFallo((e as ErrorApi).message);
    } finally {
      setCargando(false);
    }
  }, [buscar, estado, orden, direccion, cursor]);

  // La búsqueda espera a que dejes de escribir, para no pedir por cada tecla.
  useEffect(() => {
    const t = setTimeout(() => void cargar(), buscar ? 300 : 0);
    return () => clearTimeout(t);
  }, [cargar, buscar]);

  function ordenarPor(columna: typeof orden) {
    setCursor(null);
    if (columna === orden) {
      setDireccion(direccion === "asc" ? "desc" : "asc");
    } else {
      setOrden(columna);
      setDireccion("asc");
    }
  }

  async function cambiarActivo(suplidor: Suplidor) {
    const accion = suplidor.activo ? "desactivar" : "reactivar";
    if (suplidor.activo && !window.confirm(`¿Desactivar a ${suplidor.razon_social}? Dejará de aparecer en los selectores, pero conserva su historial.`)) {
      return;
    }
    await api.post(`/suplidores/${suplidor.id}/${accion}`);
    await cargar();
  }

  const datos = listado?.datos ?? [];
  const permisos = listado?.permisos ?? { editar: false, desactivar: false };
  const puedeEditar = permisos.editar && !soloLectura;
  const hayFiltro = buscar.trim() !== "" || estado !== "activos";

  return (
    <section className="pantalla">
      <div className="pantalla__cabeza">
        <div>
          <h1>Suplidores</h1>
          <p>A quién le compras la mercancía, con su vendedor y sus términos.</p>
        </div>
        {puedeEditar && (
          <Boton onClick={() => setEditando({ ...VACIO })}>Nuevo suplidor</Boton>
        )}
      </div>

      <div className="filtros">
        <input
          id="buscar"
          type="search"
          placeholder="Buscar por empresa, vendedor o número de cliente"
          aria-label="Buscar suplidores"
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
            setEstado(e.target.value as Estado);
          }}
        >
          <option value="activos">Activos</option>
          <option value="inactivos">Inactivos</option>
          <option value="todos">Todos</option>
        </select>
      </div>

      {fallo && <Aviso>{fallo}</Aviso>}

      {cargando && !listado ? (
        <p className="vacio">Cargando...</p>
      ) : datos.length === 0 ? (
        <div className="vacio-caja">
          <h2>{hayFiltro ? "Ningún suplidor coincide" : "Aún no tienes suplidores"}</h2>
          <p>
            {hayFiltro
              ? "Prueba con otra búsqueda o cambia el filtro de estado."
              : "Registra a quién le compras para poder asociarle productos y recibir compras."}
          </p>
          {hayFiltro ? (
            <Boton variante="suave" onClick={() => { setBuscar(""); setEstado("activos"); setCursor(null); }}>
              Limpiar filtros
            </Boton>
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
                  <th>
                    <button type="button" onClick={() => ordenarPor("razon_social")}>
                      Empresa {orden === "razon_social" && (direccion === "asc" ? "↑" : "↓")}
                    </button>
                  </th>
                  <th>
                    <button type="button" onClick={() => ordenarPor("numero_cliente")}>
                      N.º de cliente {orden === "numero_cliente" && (direccion === "asc" ? "↑" : "↓")}
                    </button>
                  </th>
                  <th>Teléfono</th>
                  <th>
                    <button type="button" onClick={() => ordenarPor("vendedor")}>
                      Vendedor {orden === "vendedor" && (direccion === "asc" ? "↑" : "↓")}
                    </button>
                  </th>
                  <th>Términos de pago</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {datos.map((s) => (
                  <tr key={s.id} data-inactivo={!s.activo}>
                    <td>
                      <span className="celda-principal">
                        <b>{s.razon_social}</b>
                        {s.nombre_comercial && <small>{s.nombre_comercial}</small>}
                        {!s.activo && <span className="etiqueta">Inactivo</span>}
                      </span>
                    </td>
                    <td className="numerica" data-etiqueta="N.º de cliente">{s.numero_cliente ?? "—"}</td>
                    <td className="numerica" data-etiqueta="Teléfono">{s.telefono ?? "—"}</td>
                    <td data-etiqueta="Vendedor">{s.vendedor ?? "—"}</td>
                    <td data-etiqueta="Términos de pago">{s.terminos_pago ?? "—"}</td>
                    <td className="celda-acciones">
                      <div className="acciones">
                        {puedeEditar && (
                          <button type="button" className="boton-fila" onClick={() => setEditando(s)}>
                            Editar
                          </button>
                        )}
                        {permisos.desactivar && !soloLectura && (
                          <button type="button" className="boton-fila" onClick={() => void cambiarActivo(s)}>
                            {s.activo ? "Desactivar" : "Reactivar"}
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
            <Boton
              variante="suave"
              disabled={!listado?.anterior}
              onClick={() => setCursor(listado?.anterior ?? null)}
            >
              Anterior
            </Boton>
            <Boton
              variante="suave"
              disabled={!listado?.siguiente}
              onClick={() => setCursor(listado?.siguiente ?? null)}
            >
              Siguiente
            </Boton>
          </div>
        </>
      )}

      {editando && (
        <FormularioSuplidor
          suplidor={editando}
          alCerrar={() => setEditando(null)}
          alGuardar={async () => {
            setEditando(null);
            setCursor(null);
            await cargar();
          }}
        />
      )}
    </section>
  );
}

function FormularioSuplidor({
  suplidor,
  alCerrar,
  alGuardar,
}: {
  suplidor: Suplidor;
  alCerrar: () => void;
  alGuardar: () => Promise<void>;
}) {
  const [datos, setDatos] = useState<Suplidor>(suplidor);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorApi | null>(null);
  const [tocado, setTocado] = useState(false);
  const esNuevo = suplidor.id === "";

  function campo(nombre: keyof Suplidor) {
    return {
      value: (datos[nombre] as string | null) ?? "",
      onChange: (e: React.ChangeEvent<HTMLInputElement>) => {
        setTocado(true);
        setDatos({ ...datos, [nombre]: e.target.value });
        setError((actual) => actual?.sinCampo(nombre as string) ?? null);
      },
      error: error?.campo(nombre as string),
    };
  }

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    setEnviando(true);
    setError(null);

    const cuerpo = {
      razon_social: datos.razon_social,
      nombre_comercial: datos.nombre_comercial || null,
      numero_cliente: datos.numero_cliente || null,
      telefono: datos.telefono || null,
      email: datos.email || null,
      vendedor: datos.vendedor || null,
      terminos_pago: datos.terminos_pago || null,
    };

    try {
      if (esNuevo) await api.post("/suplidores", cuerpo);
      else await api.put(`/suplidores/${datos.id}`, cuerpo);
      await alGuardar();
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
      titulo={esNuevo ? "Nuevo suplidor" : "Editar suplidor"}
      descripcion={esNuevo ? "La razón social es lo único obligatorio." : datos.razon_social}
      haycambios={tocado}
      alCerrar={alCerrar}
      pie={
        <>
          <Boton variante="suave" type="button" onClick={alCerrar}>
            Cancelar
          </Boton>
          <Boton type="submit" form="formulario-suplidor" cargando={enviando}>
            {enviando ? "Guardando..." : "Guardar"}
          </Boton>
        </>
      }
    >
      <form id="formulario-suplidor" onSubmit={enviar} noValidate>
        {errorGeneral && <Aviso>{errorGeneral}</Aviso>}

        <Campo id="razon_social" etiqueta="Razón social" required {...campo("razon_social")} />
        <Campo id="nombre_comercial" etiqueta="Nombre comercial" {...campo("nombre_comercial")} />
        <Campo
          id="numero_cliente"
          etiqueta="N.º de cliente que te asignaron"
          inputMode="numeric"
          {...campo("numero_cliente")}
        />
        <Campo id="telefono" etiqueta="Teléfono" type="tel" {...campo("telefono")} />
        <Campo id="email" etiqueta="Correo" type="email" {...campo("email")} />
        <Campo id="vendedor" etiqueta="Vendedor de contacto" {...campo("vendedor")} />
        <Campo id="terminos_pago" etiqueta="Términos de pago" placeholder="30 días, contado…" {...campo("terminos_pago")} />
      </form>
    </PanelLateral>
  );
}
