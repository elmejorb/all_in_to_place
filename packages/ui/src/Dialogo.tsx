import { useEffect, useRef, type ReactNode } from "react";

type Props = {
  titulo: string;
  descripcion?: string;
  alCerrar: () => void;
  children: ReactNode;
  pie?: ReactNode;
};

/**
 * Una caja pequeña y centrada para una tarea corta que interrumpe otra.
 *
 * No es el panel lateral: aquel es para crear y editar desde un listado, y se
 * lleva toda la altura de la pantalla. Este es para lo que pasa **encima** de
 * un trabajo a medias —dar de alta un cliente sin salir de la factura— y por
 * eso es chico: recuerda que lo de debajo sigue ahí y que se vuelve enseguida.
 *
 * Cierra con Escape o pinchando fuera, y se lleva el foco al primer campo.
 */
export function Dialogo({ titulo, descripcion, alCerrar, children, pie }: Props) {
  const caja = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const alTeclear = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        e.stopPropagation();
        alCerrar();
      }
    };

    document.addEventListener("keydown", alTeclear);
    caja.current?.querySelector<HTMLElement>("input, select, textarea")?.focus();

    return () => document.removeEventListener("keydown", alTeclear);
  }, [alCerrar]);

  return (
    <div className="dialogo" role="dialog" aria-modal="true" aria-label={titulo}>
      <div className="dialogo__fondo" onClick={alCerrar} aria-hidden="true" />
      <div className="dialogo__caja" ref={caja}>
        <header className="dialogo__cabeza">
          <div>
            <h2>{titulo}</h2>
            {descripcion && <p>{descripcion}</p>}
          </div>
          <button type="button" className="dialogo__cerrar" onClick={alCerrar} aria-label="Cerrar">
            ×
          </button>
        </header>

        <div className="dialogo__cuerpo">{children}</div>

        {pie && <footer className="dialogo__pie">{pie}</footer>}
      </div>
    </div>
  );
}
