import { useCallback, useEffect, useState } from "react";
import { Aviso, Boton, Campo, PanelLateral } from "@aiop/ui";
import { api, ErrorApi, type Categoria, type ListadoCategorias } from "../api";

const VACIA: Categoria = { id: "", nombre: "", descripcion: null, color: "pizarra" };

export function Categorias({ soloLectura }: { soloLectura: boolean }) {
  const [listado, setListado] = useState<ListadoCategorias | null>(null);
  const [buscar, setBuscar] = useState("");
  const [cargando, setCargando] = useState(true);
  const [editando, setEditando] = useState<Categoria | null>(null);
  const [fallo, setFallo] = useState<string | null>(null);

  const cargar = useCallback(async () => {
    setCargando(true);
    setFallo(null);
    const parametros = new URLSearchParams();
    if (buscar.trim()) parametros.set("buscar", buscar.trim());

    try {
      setListado(await api.get<ListadoCategorias>(`/categorias?${parametros}`));
    } catch (e) {
      setFallo((e as ErrorApi).message);
    } finally {
      setCargando(false);
    }
  }, [buscar]);

  useEffect(() => {
    const t = setTimeout(() => void cargar(), buscar ? 300 : 0);
    return () => clearTimeout(t);
  }, [cargar, buscar]);

  const datos = listado?.datos ?? [];
  const puedeEditar = (listado?.permisos.editar ?? false) && !soloLectura;
  const puedeEliminar = (listado?.permisos.desactivar ?? false) && !soloLectura;

  async function eliminar(categoria: Categoria) {
    const conProductos = (categoria.productos ?? 0) > 0;

    if (!conProductos) {
      if (!window.confirm(`¿Eliminar la categoría ${categoria.nombre}?`)) return;
      await api.borrar(`/categorias/${categoria.id}`);
      await cargar();
      return;
    }

    // CAT-03: con productos dentro, primero se dice a dónde van.
    const otras = datos.filter((c) => c.id !== categoria.id);

    if (otras.length === 0) {
      window.alert(`${categoria.nombre} tiene ${categoria.productos} producto(s) y no hay otra categoría a la cual pasarlos. Crea una primero.`);
      return;
    }
    const lista = otras.map((c, i) => `${i + 1}. ${c.nombre}`).join("\n");
    const elegido = window.prompt(
      `${categoria.nombre} tiene ${categoria.productos} producto(s).\n\n¿A cuál categoría los pasamos?\n\n${lista}\n\nEscribe el número:`,
      "1",
    );

    if (elegido === null) return;

    const destino = otras[Number(elegido) - 1];
    if (!destino) {
      window.alert("Ese número no corresponde a ninguna categoría de la lista.");
      return;
    }

    await api.borrar(`/categorias/${categoria.id}`, { reasignar_a: destino.id });
    await cargar();
  }
  const hayFiltro = buscar.trim() !== "";

  return (
    <section className="pantalla">
      <div className="pantalla__cabeza">
        <div>
          <h1>Categorías</h1>
          <p>Cómo agrupas tus productos en el catálogo y en la pantalla de venta.</p>
        </div>
        {puedeEditar && <Boton onClick={() => setEditando({ ...VACIA })}>Nueva categoría</Boton>}
      </div>

      <div className="filtros">
        <input
          id="buscar"
          type="search"
          placeholder="Buscar categoría"
          aria-label="Buscar categorías"
          value={buscar}
          onChange={(e) => setBuscar(e.target.value)}
        />
      </div>

      {fallo && <Aviso>{fallo}</Aviso>}

      {cargando && !listado ? (
        <p className="vacio">Cargando...</p>
      ) : datos.length === 0 ? (
        <div className="vacio-caja">
          <h2>{hayFiltro ? "Ninguna categoría coincide" : "Aún no tienes categorías"}</h2>
          <p>
            {hayFiltro
              ? "Prueba con otra búsqueda."
              : "Agrupa tus productos para encontrarlos rápido y ver qué se vende por grupo."}
          </p>
          {hayFiltro ? (
            <Boton variante="suave" onClick={() => setBuscar("")}>Limpiar búsqueda</Boton>
          ) : (
            puedeEditar && <Boton onClick={() => setEditando({ ...VACIA })}>Crear la primera</Boton>
          )}
        </div>
      ) : (
        <div className="tabla-caja tarjeta">
          <table className="tabla">
            <thead>
              <tr>
                <th>Categoría</th>
                <th>Descripción</th>
                <th className="derecha">Productos</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {datos.map((c) => (
                <tr key={c.id}>
                  <td>
                    <span className="celda-principal">
                      <span className="etiqueta-color" data-color={c.color}>{c.nombre}</span>
                    </span>
                  </td>
                  <td data-etiqueta="Descripción">{c.descripcion ?? "—"}</td>
                  <td className="numerica derecha" data-etiqueta="Productos">{c.productos ?? 0}</td>
                  <td className="celda-acciones">
                    <div className="acciones">
                      {puedeEditar && (
                        <button type="button" className="boton-fila" onClick={() => setEditando(c)}>
                          Editar
                        </button>
                      )}
                      {puedeEliminar && (
                        <button type="button" className="boton-fila" onClick={() => void eliminar(c)}>
                          Eliminar
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editando && (
        <FormularioCategoria
          categoria={editando}
          colores={listado?.colores ?? []}
          alCerrar={() => setEditando(null)}
          alGuardar={async () => {
            setEditando(null);
            await cargar();
          }}
        />
      )}
    </section>
  );
}

function FormularioCategoria({
  categoria,
  colores,
  alCerrar,
  alGuardar,
}: {
  categoria: Categoria;
  colores: string[];
  alCerrar: () => void;
  alGuardar: () => Promise<void>;
}) {
  const [datos, setDatos] = useState<Categoria>(categoria);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorApi | null>(null);
  const [tocado, setTocado] = useState(false);
  const esNueva = categoria.id === "";

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    setEnviando(true);
    setError(null);

    const cuerpo = {
      nombre: datos.nombre,
      descripcion: datos.descripcion || null,
      color: datos.color,
    };

    try {
      if (esNueva) await api.post("/categorias", cuerpo);
      else await api.put(`/categorias/${datos.id}`, cuerpo);
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
      titulo={esNueva ? "Nueva categoría" : "Editar categoría"}
      descripcion={esNueva ? "El nombre no se puede repetir dentro de tu empresa." : categoria.nombre}
      haycambios={tocado}
      alCerrar={alCerrar}
      pie={
        <>
          <Boton variante="suave" type="button" onClick={alCerrar}>Cancelar</Boton>
          <Boton type="submit" form="formulario-categoria" cargando={enviando}>
            {enviando ? "Guardando..." : "Guardar"}
          </Boton>
        </>
      }
    >
      <form id="formulario-categoria" onSubmit={enviar} noValidate>
        {errorGeneral && <Aviso>{errorGeneral}</Aviso>}

        <Campo
          id="nombre"
          etiqueta="Nombre"
          required
          value={datos.nombre}
          error={error?.campo("nombre")}
          onChange={(e) => {
            setTocado(true);
            setDatos({ ...datos, nombre: e.target.value });
            setError((actual) => actual?.sinCampo("nombre") ?? null);
          }}
        />

        <Campo
          id="descripcion"
          etiqueta="Descripción"
          value={datos.descripcion ?? ""}
          error={error?.campo("descripcion")}
          onChange={(e) => {
            setTocado(true);
            setDatos({ ...datos, descripcion: e.target.value });
            setError((actual) => actual?.sinCampo("descripcion") ?? null);
          }}
        />

        <fieldset className="colores">
          <legend>Color de la etiqueta</legend>
          <div className="colores__opciones">
            {colores.map((color) => (
              <label key={color} className="color" data-color={color} data-elegido={datos.color === color}>
                <input
                  type="radio"
                  name="color"
                  value={color}
                  checked={datos.color === color}
                  onChange={() => {
                    setTocado(true);
                    setDatos({ ...datos, color });
                  }}
                />
                <span>{color}</span>
              </label>
            ))}
          </div>
        </fieldset>
      </form>
    </PanelLateral>
  );
}
