import { useEffect, useRef, type ReactNode } from "react";

type Props = {
  abierto: boolean;
  titulo: string;
  descripcion?: string;
  haycambios?: boolean;
  alCerrar: () => void;
  children: ReactNode;
  pie?: ReactNode;
};

/**
 * Crear y editar ocurre en un panel lateral sobre el listado, no navegando a
 * otra pantalla (PR-01). Cierra con Escape, avisa si hay cambios sin guardar y
 * devuelve el foco al abrirse.
 */
export function PanelLateral({ abierto, titulo, descripcion, haycambios = false, alCerrar, children, pie }: Props) {
  const caja = useRef<HTMLDivElement>(null);

  function intentarCerrar() {
    if (haycambios && !window.confirm("Tienes cambios sin guardar. ¿Los descartas?")) return;
    alCerrar();
  }

  useEffect(() => {
    if (!abierto) return;

    const alTeclear = (e: KeyboardEvent) => {
      if (e.key === "Escape") intentarCerrar();
    };

    document.addEventListener("keydown", alTeclear);
    caja.current?.querySelector<HTMLElement>("input, select, textarea, button")?.focus();

    return () => document.removeEventListener("keydown", alTeclear);
  });

  if (!abierto) return null;

  return (
    <div className="panel-lateral" role="dialog" aria-modal="true" aria-label={titulo}>
      <div className="panel-lateral__fondo" onClick={intentarCerrar} aria-hidden="true" />
      <div className="panel-lateral__caja" ref={caja}>
        <header className="panel-lateral__cabeza">
          <div>
            <h2>{titulo}</h2>
            {descripcion && <p>{descripcion}</p>}
          </div>
          <button type="button" className="panel-lateral__cerrar" onClick={intentarCerrar} aria-label="Cerrar">
            ×
          </button>
        </header>

        <div className="panel-lateral__cuerpo">{children}</div>

        {pie && <footer className="panel-lateral__pie">{pie}</footer>}
      </div>
    </div>
  );
}
