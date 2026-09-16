import { useCallback, useEffect, useState } from "react";
import { Aviso, Boton, Campo, PanelLateral, useAvisar } from "@aiop/ui";
import { ImportarProductos } from "./ImportarProductos";
import {
  api,
  descargar,
  ErrorApi,
  type Categoria,
  type ListadoProductos,
  type Producto,
  type Suplidor,
} from "../api";

const VACIO: Producto = {
  id: "",
  nombre: "",
  sku: null,
  codigo_barras: null,
  descripcion: null,
  unidad: "unidad",
  precio: "0.00",
  costo: "0.00",
  impuesto: "0",
  margen: null,
  vende_bajo_costo: false,
  es_servicio: false,
  activo: true,
  existencia: 0,
  existencia_minima: 0,
  estado_existencia: "agotado",
  categoria: null,
  suplidor: null,
};

const ETIQUETA_EXISTENCIA: Record<string, string> = {
  agotado: "Agotado",
  bajo: "Bajo mínimo",
  normal: "Normal",
};

export function Productos({ soloLectura }: { soloLectura: boolean }) {
  const [listado, setListado] = useState<ListadoProductos | null>(null);
  const [buscar, setBuscar] = useState("");
  const [categoria, setCategoria] = useState("");
  const [suplidor, setSuplidor] = useState("");
  const [existencia, setExistencia] = useState("");
  const [tipo, setTipo] = useState("");
  const [estado, setEstado] = useState("activos");
  const [cursor, setCursor] = useState<string | null>(null);
  const [cargando, setCargando] = useState(true);
  const [editando, setEditando] = useState<Producto | null>(null);
  const [ajustando, setAjustando] = useState<Producto | null>(null);
  const [fallo, setFallo] = useState<string | null>(null);
  const [importando, setImportando] = useState(false);
  const avisar = useAvisar();

  // Catálogos para los filtros y el formulario.
  const [categorias, setCategorias] = useState<Categoria[]>([]);
  const [suplidores, setSuplidores] = useState<Suplidor[]>([]);

  useEffect(() => {
    void (async () => {
      const [c, s] = await Promise.all([
        api.get<{ datos: Categoria[] }>("/categorias?por_pagina=100"),
        api.get<{ datos: Suplidor[] }>("/suplidores?por_pagina=100"),
      ]);
      setCategorias(c.datos);
      setSuplidores(s.datos);
    })().catch(() => undefined);
  }, []);

  const cargar = useCallback(async () => {
    setCargando(true);
    setFallo(null);
    const p = new URLSearchParams({ estado });
    if (buscar.trim()) p.set("buscar", buscar.trim());
    if (categoria) p.set("categoria", categoria);
    if (suplidor) p.set("suplidor", suplidor);
    if (existencia) p.set("existencia", existencia);
    if (tipo) p.set("tipo", tipo);
    if (cursor) p.set("cursor", cursor);

    try {
      setListado(await api.get<ListadoProductos>(`/productos?${p}`));
    } catch (e) {
      setFallo((e as ErrorApi).message);
    } finally {
      setCargando(false);
    }
  }, [buscar, categoria, suplidor, existencia, tipo, estado, cursor]);

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
    setCategoria("");
    setSuplidor("");
    setExistencia("");
    setTipo("");
    setEstado("activos");
  }

  async function exportar() {
    const p = new URLSearchParams({ estado });
    if (buscar.trim()) p.set("buscar", buscar.trim());
    if (categoria) p.set("categoria", categoria);
    if (suplidor) p.set("suplidor", suplidor);
    if (existencia) p.set("existencia", existencia);
    if (tipo) p.set("tipo", tipo);

    try {
      await descargar(`/productos/exportar?${p}`);
      avisar("Exportado con los filtros que tienes puestos.");
    } catch (e) {
      setFallo((e as ErrorApi).message);
    }
  }

  async function cambiarActivo(producto: Producto) {
    const accion = producto.activo ? "desactivar" : "reactivar";
    if (producto.activo && !window.confirm(`¿Desactivar ${producto.nombre}? Dejará de aparecer al facturar, pero conserva su historial.`)) {
      return;
    }
    await api.post(`/productos/${producto.id}/${accion}`);
    await cargar();
  }

  const datos = listado?.datos ?? [];
  const permisos = listado?.permisos ?? { editar: false, desactivar: false, ver_costos: false };
  const puedeEditar = permisos.editar && !soloLectura;
  const hayFiltro = Boolean(buscar.trim() || categoria || suplidor || existencia || tipo || estado !== "activos");

  return (
    <section className="pantalla">
      <div className="pantalla__cabeza">
        <div>
          <h1>Productos</h1>
          <p>Tu catálogo, con lo que cuesta, a cuánto se vende y cuánto queda.</p>
        </div>
        <div className="pantalla__acciones">
          <Boton variante="suave" onClick={() => void exportar()}>Exportar</Boton>
          {puedeEditar && (
            <Boton variante="suave" onClick={() => setImportando(true)}>Importar</Boton>
          )}
          {puedeEditar && <Boton onClick={() => setEditando({ ...VACIO })}>Nuevo producto</Boton>}
        </div>
      </div>

      <div className="filtros">
        <input
          id="buscar"
          type="search"
          placeholder="Buscar por nombre, código interno o código de barras"
          aria-label="Buscar productos"
          value={buscar}
          onChange={(e) => filtrar(() => setBuscar(e.target.value))}
        />

        <select id="categoria" aria-label="Categoría" value={categoria} onChange={(e) => filtrar(() => setCategoria(e.target.value))}>
          <option value="">Toda categoría</option>
          {categorias.map((c) => (
            <option key={c.id} value={c.id}>{c.nombre}</option>
          ))}
        </select>

        <select id="suplidor" aria-label="Suplidor" value={suplidor} onChange={(e) => filtrar(() => setSuplidor(e.target.value))}>
          <option value="">Todo suplidor</option>
          {suplidores.map((s) => (
            <option key={s.id} value={s.id}>{s.nombre_comercial ?? s.razon_social}</option>
          ))}
        </select>

        <select id="existencia" aria-label="Existencia" value={existencia} onChange={(e) => filtrar(() => setExistencia(e.target.value))}>
          <option value="">Toda existencia</option>
          <option value="agotado">Agotados</option>
          <option value="bajo">Bajo mínimo</option>
          <option value="normal">Con existencia</option>
        </select>

        <select id="tipo" aria-label="Tipo" value={tipo} onChange={(e) => filtrar(() => setTipo(e.target.value))}>
          <option value="">Productos y servicios</option>
          <option value="producto">Solo productos</option>
          <option value="servicio">Solo servicios</option>
        </select>

        <select id="estado" aria-label="Estado" value={estado} onChange={(e) => filtrar(() => setEstado(e.target.value))}>
          <option value="activos">Activos</option>
          <option value="inactivos">Inactivos</option>
          <option value="todos">Todos</option>
        </select>

        {hayFiltro && (
          <button type="button" className="boton-fila" onClick={limpiar}>Limpiar filtros</button>
        )}
      </div>

      {fallo && <Aviso>{fallo}</Aviso>}

      {cargando && !listado ? (
        <p className="vacio">Cargando...</p>
      ) : datos.length === 0 ? (
        <div className="vacio-caja">
          <h2>{hayFiltro ? "Ningún producto coincide" : "Aún no tienes productos"}</h2>
          <p>
            {hayFiltro
              ? "Prueba con otra búsqueda o quita algún filtro."
              : "Carga tu catálogo para poder facturar y llevar existencias."}
          </p>
          {hayFiltro ? (
            <Boton variante="suave" onClick={limpiar}>Limpiar filtros</Boton>
          ) : (
            puedeEditar && <Boton onClick={() => setEditando({ ...VACIO })}>Crear el primero</Boton>
          )}
        </div>
      ) : (
        <>
          <div className="tabla-caja tarjeta">
            <table className="tabla">
              <thead>
                <tr>
                  <th>Producto</th>
                  <th>Categoría</th>
                  <th className="derecha">Existencia</th>
                  {permisos.ver_costos && <th className="derecha">Costo</th>}
                  <th className="derecha">Precio</th>
                  {permisos.ver_costos && <th className="derecha">Margen</th>}
                  <th className="derecha">Imp.</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {datos.map((p) => (
                  <tr key={p.id} data-inactivo={!p.activo}>
                    <td>
                      <span className="celda-principal">
                        <b>{p.nombre}</b>
                        <small>
                          {p.sku ?? "sin código"}
                          {p.es_servicio ? " · servicio" : ` · ${p.unidad}`}
                        </small>
                        {!p.activo && <span className="etiqueta">Inactivo</span>}
                      </span>
                    </td>
                    <td data-etiqueta="Categoría">
                      {p.categoria ? (
                        <span className="etiqueta-color" data-color={p.categoria.color}>{p.categoria.nombre}</span>
                      ) : (
                        "—"
                      )}
                    </td>
                    <td className="numerica derecha" data-etiqueta="Existencia">
                      {p.es_servicio ? (
                        <span className="tenue">No aplica</span>
                      ) : (
                        <span className="existencia" data-estado={p.estado_existencia}>
                          {p.existencia}
                          {p.estado_existencia !== "normal" && (
                            <small>{ETIQUETA_EXISTENCIA[p.estado_existencia ?? ""]}</small>
                          )}
                        </span>
                      )}
                    </td>
                    {permisos.ver_costos && (
                      <td className="numerica derecha" data-etiqueta="Costo">{p.costo}</td>
                    )}
                    <td className="numerica derecha" data-etiqueta="Precio">{p.precio}</td>
                    {permisos.ver_costos && (
                      <td className="numerica derecha" data-etiqueta="Margen">
                        <span data-alerta={p.vende_bajo_costo ? "true" : undefined}>
                          {p.margen === null ? "—" : `${p.margen}%`}
                        </span>
                      </td>
                    )}
                    <td className="numerica derecha" data-etiqueta="Impuesto">{p.impuesto}%</td>
                    <td className="celda-acciones">
                      <div className="acciones">
                        {puedeEditar && !p.es_servicio && (
                          <button type="button" className="boton-fila" onClick={() => setAjustando(p)}>
                            Ajustar
                          </button>
                        )}
                        {puedeEditar && (
                          <button type="button" className="boton-fila" onClick={() => setEditando(p)}>
                            Editar
                          </button>
                        )}
                        {permisos.desactivar && !soloLectura && (
                          <button type="button" className="boton-fila" onClick={() => void cambiarActivo(p)}>
                            {p.activo ? "Desactivar" : "Reactivar"}
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
        <FormularioProducto
          producto={editando}
          categorias={categorias}
          suplidores={suplidores}
          unidades={listado?.unidades ?? []}
          alCerrar={() => setEditando(null)}
          alGuardar={async () => {
            setEditando(null);
            setCursor(null);
            await cargar();
          }}
        />
      )}

      {importando && (
        <ImportarProductos
          alCerrar={() => setImportando(false)}
          alTerminar={async () => {
            setCursor(null);
            await cargar();
          }}
        />
      )}

      {ajustando && (
        <FormularioAjuste
          producto={ajustando}
          motivos={listado?.motivos_ajuste ?? []}
          alCerrar={() => setAjustando(null)}
          alGuardar={async () => {
            setAjustando(null);
            await cargar();
          }}
        />
      )}
    </section>
  );
}

function FormularioProducto({
  producto,
  categorias,
  suplidores,
  unidades,
  alCerrar,
  alGuardar,
}: {
  producto: Producto;
  categorias: Categoria[];
  suplidores: Suplidor[];
  unidades: string[];
  alCerrar: () => void;
  alGuardar: () => Promise<void>;
}) {
  const esNuevo = producto.id === "";
  const [datos, setDatos] = useState({
    nombre: producto.nombre,
    sku: producto.sku ?? "",
    codigo_barras: producto.codigo_barras ?? "",
    descripcion: producto.descripcion ?? "",
    categoria: producto.categoria?.id ?? "",
    suplidor: producto.suplidor?.id ?? "",
    unidad: producto.unidad,
    costo: producto.costo ?? "0.00",
    precio: producto.precio,
    impuesto: producto.impuesto,
    existencia_minima: String(producto.existencia_minima ?? 0),
    existencia_inicial: "0",
    es_servicio: producto.es_servicio,
  });
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorApi | null>(null);
  const [tocado, setTocado] = useState(false);

  function cambiar(campo: keyof typeof datos, valor: string | boolean) {
    setTocado(true);
    setDatos((d) => ({ ...d, [campo]: valor }));
    setError((actual) => actual?.sinCampo(campo as string) ?? null);
  }

  // Margen en vivo, mientras se escribe (PRO-02).
  const costoCentavos = Math.round(Number(datos.costo.replace(",", ".")) * 100) || 0;
  const precioCentavos = Math.round(Number(datos.precio.replace(",", ".")) * 100) || 0;
  const margen = precioCentavos > 0 ? Math.round(((precioCentavos - costoCentavos) / precioCentavos) * 10000) / 100 : null;
  const bajoCosto = precioCentavos > 0 && precioCentavos < costoCentavos;

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    setEnviando(true);
    setError(null);

    const cuerpo: Record<string, unknown> = {
      nombre: datos.nombre,
      sku: datos.sku || null,
      codigo_barras: datos.codigo_barras || null,
      descripcion: datos.descripcion || null,
      categoria: datos.categoria || null,
      suplidor: datos.suplidor || null,
      unidad: datos.unidad,
      costo: datos.costo,
      precio: datos.precio,
      impuesto: datos.impuesto,
      es_servicio: datos.es_servicio,
    };

    if (!datos.es_servicio) {
      cuerpo.existencia_minima = datos.existencia_minima;
      if (esNuevo) cuerpo.existencia_inicial = datos.existencia_inicial;
    }

    try {
      if (esNuevo) await api.post("/productos", cuerpo);
      else await api.put(`/productos/${producto.id}`, cuerpo);
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
      titulo={esNuevo ? "Nuevo producto" : "Editar producto"}
      descripcion={esNuevo ? "El nombre es lo único obligatorio." : producto.nombre}
      haycambios={tocado}
      alCerrar={alCerrar}
      pie={
        <>
          <Boton variante="suave" type="button" onClick={alCerrar}>Cancelar</Boton>
          <Boton type="submit" form="formulario-producto" cargando={enviando}>
            {enviando ? "Guardando..." : "Guardar"}
          </Boton>
        </>
      }
    >
      <form id="formulario-producto" onSubmit={enviar} noValidate>
        {errorGeneral && <Aviso>{errorGeneral}</Aviso>}

        <Campo
          id="nombre"
          etiqueta="Nombre del producto"
          required
          value={datos.nombre}
          error={error?.campo("nombre")}
          onChange={(e) => cambiar("nombre", e.target.value)}
        />

        <label className="interruptor">
          <input
            id="es_servicio"
            type="checkbox"
            checked={datos.es_servicio}
            onChange={(e) => cambiar("es_servicio", e.target.checked)}
          />
          <span>
            Es un servicio
            <small>Los servicios no llevan inventario.</small>
          </span>
        </label>

        <div className="fila-campos">
          <Campo
            id="sku"
            etiqueta="Código interno"
            value={datos.sku}
            error={error?.campo("sku")}
            onChange={(e) => cambiar("sku", e.target.value)}
          />
          <Campo
            id="codigo_barras"
            etiqueta="Código de barras"
            inputMode="numeric"
            value={datos.codigo_barras}
            error={error?.campo("codigo_barras")}
            onChange={(e) => cambiar("codigo_barras", e.target.value)}
          />
        </div>

        <div className="campo">
          <label htmlFor="categoria_campo">Categoría</label>
          <select
            id="categoria_campo"
            value={datos.categoria}
            onChange={(e) => cambiar("categoria", e.target.value)}
          >
            <option value="">Sin categoría</option>
            {categorias.map((c) => (
              <option key={c.id} value={c.id}>{c.nombre}</option>
            ))}
          </select>
          {error?.campo("categoria") && <span className="error">{error.campo("categoria")}</span>}
        </div>

        <div className="campo">
          <label htmlFor="suplidor_campo">Suplidor</label>
          <select
            id="suplidor_campo"
            value={datos.suplidor}
            onChange={(e) => cambiar("suplidor", e.target.value)}
          >
            <option value="">Sin suplidor</option>
            {suplidores.map((s) => (
              <option key={s.id} value={s.id}>{s.nombre_comercial ?? s.razon_social}</option>
            ))}
          </select>
        </div>

        <div className="campo">
          <label htmlFor="unidad_campo">Unidad de medida</label>
          <select id="unidad_campo" value={datos.unidad} onChange={(e) => cambiar("unidad", e.target.value)}>
            {unidades.map((u) => (
              <option key={u} value={u}>{u}</option>
            ))}
          </select>
        </div>

        <div className="fila-campos">
          <Campo
            id="costo"
            etiqueta="Costo"
            inputMode="decimal"
            value={datos.costo}
            error={error?.campo("costo")}
            onChange={(e) => cambiar("costo", e.target.value)}
          />
          <Campo
            id="precio"
            etiqueta="Precio de venta"
            inputMode="decimal"
            value={datos.precio}
            error={error?.campo("precio")}
            onChange={(e) => cambiar("precio", e.target.value)}
          />
        </div>

        <p className="margen-vivo" data-alerta={bajoCosto ? "true" : undefined} id="margen-vivo">
          {margen === null
            ? "Pon un precio para ver el margen."
            : bajoCosto
              ? `Margen ${margen}% — lo estás vendiendo por debajo del costo.`
              : `Margen ${margen}% · ganas ${((precioCentavos - costoCentavos) / 100).toFixed(2)} por ${datos.unidad}.`}
        </p>

        <div className="fila-campos">
          <Campo
            id="impuesto"
            etiqueta="Impuesto (%)"
            inputMode="decimal"
            value={datos.impuesto}
            error={error?.campo("impuesto")}
            onChange={(e) => cambiar("impuesto", e.target.value)}
          />
          {!datos.es_servicio && (
            <Campo
              id="existencia_minima"
              etiqueta="Existencia mínima"
              inputMode="decimal"
              value={datos.existencia_minima}
              error={error?.campo("existencia_minima")}
              onChange={(e) => cambiar("existencia_minima", e.target.value)}
            />
          )}
        </div>

        {!datos.es_servicio && esNuevo && (
          <Campo
            id="existencia_inicial"
            etiqueta="Existencia inicial"
            inputMode="decimal"
            value={datos.existencia_inicial}
            error={error?.campo("existencia_inicial")}
            onChange={(e) => cambiar("existencia_inicial", e.target.value)}
          />
        )}

        {!datos.es_servicio && !esNuevo && (
          <p className="pie">
            La existencia no se edita aquí: se cambia con un ajuste, para que quede registrado quién
            lo hizo y por qué.
          </p>
        )}
      </form>
    </PanelLateral>
  );
}

function FormularioAjuste({
  producto,
  motivos,
  alCerrar,
  alGuardar,
}: {
  producto: Producto;
  motivos: string[];
  alCerrar: () => void;
  alGuardar: () => Promise<void>;
}) {
  const [contado, setContado] = useState(String(producto.existencia ?? 0));
  const [motivo, setMotivo] = useState(motivos[0] ?? "conteo");
  const [comentario, setComentario] = useState("");
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorApi | null>(null);

  const diferencia = Number(contado.replace(",", ".")) - Number(producto.existencia ?? 0);

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    setEnviando(true);
    setError(null);

    try {
      await api.post(`/productos/${producto.id}/ajustar`, { contado, motivo, comentario: comentario || null });
      await alGuardar();
    } catch (e) {
      setError(e as ErrorApi);
    } finally {
      setEnviando(false);
    }
  }

  return (
    <PanelLateral
      abierto
      titulo="Ajustar existencia"
      descripcion={producto.nombre}
      alCerrar={alCerrar}
      pie={
        <>
          <Boton variante="suave" type="button" onClick={alCerrar}>Cancelar</Boton>
          <Boton type="submit" form="formulario-ajuste" cargando={enviando}>
            {enviando ? "Registrando..." : "Registrar ajuste"}
          </Boton>
        </>
      }
    >
      <form id="formulario-ajuste" onSubmit={enviar} noValidate>
        {error && Object.keys(error.errores).length === 0 && <Aviso>{error.message}</Aviso>}

        <p className="vacio sin-margen">
          El sistema tiene <b>{producto.existencia}</b> {producto.unidad}(s). Escribe lo que contaste
          de verdad y la diferencia queda registrada.
        </p>

        <Campo
          id="contado"
          etiqueta="Cantidad contada"
          inputMode="decimal"
          required
          value={contado}
          error={error?.campo("contado")}
          onChange={(e) => setContado(e.target.value)}
        />

        <p className="margen-vivo" data-alerta={diferencia < 0 ? "true" : undefined} id="diferencia-viva">
          {diferencia === 0
            ? "No hay diferencia: no se registrará ningún movimiento."
            : diferencia > 0
              ? `Sobran ${diferencia} — entrarán al inventario.`
              : `Faltan ${Math.abs(diferencia)} — saldrán del inventario.`}
        </p>

        <div className="campo">
          <label htmlFor="motivo">Motivo</label>
          <select id="motivo" value={motivo} onChange={(e) => setMotivo(e.target.value)}>
            {motivos.map((m) => (
              <option key={m} value={m}>{m}</option>
            ))}
          </select>
          {error?.campo("motivo") && <span className="error">{error.campo("motivo")}</span>}
        </div>

        <Campo
          id="comentario"
          etiqueta="Comentario"
          value={comentario}
          error={error?.campo("comentario")}
          onChange={(e) => setComentario(e.target.value)}
        />
      </form>
    </PanelLateral>
  );
}
