import { useEffect, useRef, useState, type ReactNode } from "react";

export type Modulo = {
  clave: string;
  titulo: string;
  icono: ReactNode;
  grupo?: string;
};

type Props = {
  marca: string;
  modulos: Modulo[];
  activo: string;
  alElegir: (clave: string) => void;
  cabecera?: ReactNode;
  acciones?: ReactNode;
  children: ReactNode;
};

/**
 * Estructura de la aplicación: menú lateral por módulo, barra superior y
 * contenido a la derecha (docs/07-interfaz.md).
 *
 * El menú se pliega a solo iconos para ganar ancho en pantallas de caja, y en
 * móvil se convierte en un cajón que se abre desde la barra.
 */
export function Estructura({ marca, modulos, activo, alElegir, cabecera, acciones, children }: Props) {
  const [plegado, setPlegado] = useState(() => localStorage.getItem("aiop.menu") === "plegado");
  const [abiertoMovil, setAbiertoMovil] = useState(false);

  useEffect(() => {
    localStorage.setItem("aiop.menu", plegado ? "plegado" : "abierto");
  }, [plegado]);

  const grupos = modulos.reduce<Record<string, Modulo[]>>((acc, m) => {
    const grupo = m.grupo ?? "";
    (acc[grupo] ??= []).push(m);
    return acc;
  }, {});

  return (
    <div className="estructura" data-plegado={plegado} data-abierto={abiertoMovil}>
      <aside className="rail" aria-label="Módulos">
        <div className="rail__marca">
          <span className="rail__logo" aria-hidden="true">◧</span>
          <b>{marca}</b>
          <button
            type="button"
            className="rail__plegar"
            onClick={() => setPlegado((p) => !p)}
            aria-label={plegado ? "Expandir el menú" : "Plegar el menú"}
            title={plegado ? "Expandir el menú" : "Plegar el menú"}
          >
            {plegado ? "»" : "«"}
          </button>
        </div>

        <nav className="rail__nav">
          {Object.entries(grupos).map(([grupo, items]) => (
            <div className="rail__grupo" key={grupo || "sin-grupo"}>
              {grupo && <p className="rail__titulo">{grupo}</p>}
              {items.map((m) => (
                <button
                  key={m.clave}
                  type="button"
                  className="rail__item"
                  aria-current={activo === m.clave ? "page" : undefined}
                  title={m.titulo}
                  onClick={() => {
                    alElegir(m.clave);
                    setAbiertoMovil(false);
                  }}
                >
                  <span className="rail__icono" aria-hidden="true">{m.icono}</span>
                  <span className="rail__texto">{m.titulo}</span>
                </button>
              ))}
            </div>
          ))}
        </nav>
      </aside>

      <button
        type="button"
        className="estructura__velo"
        aria-hidden={!abiertoMovil}
        tabIndex={-1}
        onClick={() => setAbiertoMovil(false)}
      />

      <div className="estructura__cuerpo">
        <header className="barra-superior">
          <button
            type="button"
            className="barra-superior__menu"
            onClick={() => setAbiertoMovil(true)}
            aria-label="Abrir el menú"
          >
            ☰
          </button>
          {cabecera}
          <div className="barra-superior__acciones">{acciones}</div>
        </header>

        <main className="contenido">{children}</main>
      </div>
    </div>
  );
}

/** Menú desplegable anclado a un botón. Cierra con Escape o al hacer clic fuera. */
export function Desplegable({
  etiqueta,
  titulo,
  children,
  alineado = "izquierda",
}: {
  etiqueta: ReactNode;
  titulo?: string;
  children: (cerrar: () => void) => ReactNode;
  alineado?: "izquierda" | "derecha";
}) {
  const [abierto, setAbierto] = useState(false);
  const caja = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!abierto) return;

    const alTeclear = (e: KeyboardEvent) => e.key === "Escape" && setAbierto(false);
    const alClic = (e: MouseEvent) => {
      if (!caja.current?.contains(e.target as Node)) setAbierto(false);
    };

    document.addEventListener("keydown", alTeclear);
    document.addEventListener("mousedown", alClic);
    return () => {
      document.removeEventListener("keydown", alTeclear);
      document.removeEventListener("mousedown", alClic);
    };
  }, [abierto]);

  return (
    <div className="desplegable" ref={caja}>
      <button
        type="button"
        className="desplegable__boton"
        aria-expanded={abierto}
        aria-haspopup="menu"
        title={titulo}
        onClick={() => setAbierto((a) => !a)}
      >
        {etiqueta}
        <span className="desplegable__punta" aria-hidden="true">▾</span>
      </button>

      {abierto && (
        <div className="desplegable__menu" data-alineado={alineado} role="menu">
          {children(() => setAbierto(false))}
        </div>
      )}
    </div>
  );
}
