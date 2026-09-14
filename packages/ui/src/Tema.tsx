import { useEffect, useState } from "react";

type Tema = "sistema" | "claro" | "oscuro";

const CLAVE = "aiop.tema";

/**
 * Interruptor de tema (IU-03).
 *
 * Tres estados, no dos: lo normal es seguir al sistema, y quien quiera fijarlo
 * puede. La elección se recuerda en el navegador de cada persona.
 */
export function InterruptorTema() {
  const [tema, setTema] = useState<Tema>(() => (localStorage.getItem(CLAVE) as Tema) ?? "sistema");

  useEffect(() => {
    if (tema === "sistema") {
      document.documentElement.removeAttribute("data-tema");
      localStorage.removeItem(CLAVE);
    } else {
      document.documentElement.setAttribute("data-tema", tema);
      localStorage.setItem(CLAVE, tema);
    }
  }, [tema]);

  const siguiente: Record<Tema, Tema> = { sistema: "claro", claro: "oscuro", oscuro: "sistema" };
  const icono: Record<Tema, string> = { sistema: "◐", claro: "☀", oscuro: "☾" };
  const nombre: Record<Tema, string> = { sistema: "Tema del sistema", claro: "Tema claro", oscuro: "Tema oscuro" };

  return (
    <button
      type="button"
      className="boton-icono"
      onClick={() => setTema(siguiente[tema])}
      title={`${nombre[tema]} — clic para cambiar`}
      aria-label={`${nombre[tema]}. Clic para cambiar de tema.`}
      data-tema-actual={tema}
    >
      <span aria-hidden="true">{icono[tema]}</span>
    </button>
  );
}
