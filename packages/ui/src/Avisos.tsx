import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";

type Tono = "bien" | "error" | "aviso";
type Nota = { id: number; texto: string; tono: Tono };

const Contexto = createContext<(texto: string, tono?: Tono) => void>(() => undefined);

/**
 * Avisos de resultado (IU-01, PR-08).
 *
 * En el sistema actual guardar no responde nada: uno se queda mirando la
 * pantalla sin saber si pasó algo. Aquí toda acción contesta.
 */
export function ProveedorAvisos({ children }: { children: ReactNode }) {
  const [notas, setNotas] = useState<Nota[]>([]);

  const avisar = useCallback((texto: string, tono: Tono = "bien") => {
    setNotas((actuales) => [...actuales, { id: Date.now() + Math.random(), texto, tono }]);
  }, []);

  return (
    <Contexto.Provider value={avisar}>
      {children}
      <div className="avisos" role="status" aria-live="polite">
        {notas.map((n) => (
          <Nota key={n.id} nota={n} alCerrar={() => setNotas((a) => a.filter((x) => x.id !== n.id))} />
        ))}
      </div>
    </Contexto.Provider>
  );
}

function Nota({ nota, alCerrar }: { nota: Nota; alCerrar: () => void }) {
  useEffect(() => {
    // Los errores se quedan hasta que se cierren; lo bueno se va solo.
    if (nota.tono === "error") return;
    const t = setTimeout(alCerrar, 4000);
    return () => clearTimeout(t);
  }, [nota.tono, alCerrar]);

  return (
    <div className="avisos__nota" data-tono={nota.tono}>
      <span>{nota.texto}</span>
      <button type="button" onClick={alCerrar} aria-label="Cerrar el aviso">×</button>
    </div>
  );
}

export function useAvisar() {
  return useContext(Contexto);
}
