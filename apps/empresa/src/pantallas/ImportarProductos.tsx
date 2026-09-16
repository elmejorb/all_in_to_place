import { useRef, useState } from "react";
import { Aviso, Boton, PanelLateral, useAvisar } from "@aiop/ui";
import { api, descargar, ErrorApi, type Importacion } from "../api";

const ETIQUETA_ACCION: Record<string, string> = {
  nuevo: "Nuevo",
  actualiza: "Actualiza",
  error: "Error",
};

/**
 * Importación en tres pasos: descargar la plantilla, revisar qué traería el
 * archivo, confirmar (PRO-07). Entre el paso dos y el tres no se guarda nada.
 */
export function ImportarProductos({
  alCerrar,
  alTerminar,
}: {
  alCerrar: () => void;
  alTerminar: () => Promise<void>;
}) {
  const [archivo, setArchivo] = useState<File | null>(null);
  const [actualizar, setActualizar] = useState(false);
  const [previa, setPrevia] = useState<Importacion | null>(null);
  const [trabajando, setTrabajando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const entrada = useRef<HTMLInputElement>(null);
  const avisar = useAvisar();

  const paso = previa ? 2 : 1;

  async function revisar() {
    if (!archivo) return;
    setTrabajando(true);
    setError(null);

    const cuerpo = new FormData();
    cuerpo.append("archivo", archivo);
    cuerpo.append("actualizar_existentes", actualizar ? "1" : "0");

    try {
      setPrevia(await api.subir<Importacion>("/productos/importar", cuerpo));
    } catch (e) {
      setError((e as ErrorApi).message);
    } finally {
      setTrabajando(false);
    }
  }

  async function confirmar() {
    if (!previa) return;
    setTrabajando(true);
    setError(null);

    try {
      const r = await api.post<{ nuevos: number; actualizados: number }>(`/importaciones/${previa.id}/confirmar`);
      avisar(`Importación lista: ${r.nuevos} producto(s) nuevo(s) y ${r.actualizados} actualizado(s).`);
      await alTerminar();
      alCerrar();
    } catch (e) {
      setError((e as ErrorApi).message);
    } finally {
      setTrabajando(false);
    }
  }

  function volver() {
    if (previa) void api.post(`/importaciones/${previa.id}/descartar`).catch(() => undefined);
    setPrevia(null);
    setError(null);
  }

  return (
    <PanelLateral
      abierto
      titulo="Importar productos"
      descripcion={paso === 1 ? "Paso 1 de 2: elige el archivo" : "Paso 2 de 2: revisa antes de aplicar"}
      alCerrar={alCerrar}
      pie={
        paso === 1 ? (
          <>
            <Boton variante="suave" type="button" onClick={alCerrar}>Cancelar</Boton>
            <Boton type="button" cargando={trabajando} disabled={!archivo} onClick={() => void revisar()}>
              {trabajando ? "Revisando..." : "Revisar archivo"}
            </Boton>
          </>
        ) : (
          <>
            <Boton variante="suave" type="button" onClick={volver}>Elegir otro archivo</Boton>
            <Boton
              type="button"
              cargando={trabajando}
              disabled={!previa?.puede_aplicarse}
              onClick={() => void confirmar()}
            >
              {trabajando ? "Importando..." : `Importar ${(previa?.nuevas ?? 0) + (previa?.actualiza ?? 0)} producto(s)`}
            </Boton>
          </>
        )
      }
    >
      {error && <Aviso>{error}</Aviso>}

      {paso === 1 ? (
        <div className="importar">
          <section className="importar__paso">
            <h3>1. Empieza por la plantilla</h3>
            <p className="vacio sin-margen">
              Trae los encabezados que el sistema entiende y dos ejemplos. Ábrela en Excel, llena tus
              productos y guárdala como CSV.
            </p>
            <Boton
              variante="suave"
              type="button"
              onClick={() => void descargar("/productos/plantilla")}
            >
              Descargar plantilla
            </Boton>
          </section>

          <section className="importar__paso">
            <h3>2. Sube tu archivo</h3>
            <input
              id="archivo"
              ref={entrada}
              type="file"
              accept=".csv,text/csv"
              onChange={(e) => setArchivo(e.target.files?.[0] ?? null)}
            />
            {archivo && <p className="vacio sin-margen">Elegido: <b>{archivo.name}</b></p>}

            <label className="interruptor">
              <input
                id="actualizar_existentes"
                type="checkbox"
                checked={actualizar}
                onChange={(e) => setActualizar(e.target.checked)}
              />
              <span>
                Actualizar los que ya existen
                <small>
                  Busca por código interno. Sin esto, un código repetido se marca como error en vez
                  de cambiar el producto.
                </small>
              </span>
            </label>

            <p className="vacio sin-margen">
              La existencia solo se usa al crear productos nuevos. En los que ya existen no se toca:
              para eso está el ajuste, que deja constancia de quién lo hizo.
            </p>
          </section>
        </div>
      ) : (
        <div className="importar">
          <div className="resumen">
            <div className="resumen__dato" data-tono="bien">
              <b>{previa?.nuevas}</b>
              <small>Nuevos</small>
            </div>
            <div className="resumen__dato" data-tono="marca">
              <b>{previa?.actualiza}</b>
              <small>Actualizan</small>
            </div>
            <div className="resumen__dato" data-tono={previa && previa.errores > 0 ? "peligro" : undefined}>
              <b>{previa?.errores}</b>
              <small>Con error</small>
            </div>
          </div>

          {previa && previa.errores > 0 && (
            <Aviso tono="aviso">
              Las filas con error no se importan; las demás sí. Puedes importar así y corregir el
              resto después, o arreglar el archivo y volver a subirlo.
            </Aviso>
          )}

          {previa && previa.errores > 0 && (
            <Boton
              variante="suave"
              type="button"
              onClick={() => void descargar(`/importaciones/${previa.id}/errores`)}
            >
              Descargar lo que hay que arreglar
            </Boton>
          )}

          {!previa?.puede_aplicarse && (
            <Aviso>Ninguna fila se puede importar. Corrige el archivo y vuelve a subirlo.</Aviso>
          )}

          <div className="tabla-caja">
            <table className="tabla tabla--previa">
              <thead>
                <tr>
                  <th>Fila</th>
                  <th>Producto</th>
                  <th>Qué pasará</th>
                </tr>
              </thead>
              <tbody>
                {previa?.filas.map((f) => (
                  <tr key={f.numero} data-accion={f.accion}>
                    <td className="numerica" data-etiqueta="Fila">{f.numero}</td>
                    <td data-etiqueta="Producto">
                      <span className="celda-principal">
                        <b>{f.nombre || "(sin nombre)"}</b>
                        {f.sku && <small>{f.sku}</small>}
                      </span>
                    </td>
                    <td data-etiqueta="Qué pasará">
                      <span className="etiqueta" data-tono={f.accion === "error" ? "aviso" : f.accion === "nuevo" ? "marca" : undefined}>
                        {ETIQUETA_ACCION[f.accion]}
                      </span>
                      {f.errores.length > 0 && (
                        <ul className="errores-fila">
                          {f.errores.map((mensaje, i) => (
                            <li key={i}>{mensaje}</li>
                          ))}
                        </ul>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {previa && previa.total > previa.filas.length && (
            <p className="vacio sin-margen">
              Se muestran las primeras {previa.filas.length} de {previa.total} filas.
            </p>
          )}
        </div>
      )}
    </PanelLateral>
  );
}
