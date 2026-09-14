import type { ButtonHTMLAttributes, ReactNode } from "react";

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  cargando?: boolean;
  variante?: "principal" | "suave" | "peligro" | "fantasma";
  children: ReactNode;
};

/** Botón con estado de carga visible: nunca queda sin responder (IU-01). */
export function Boton({ cargando = false, variante = "principal", children, ...resto }: Props) {
  return (
    <button
      className="boton"
      data-variante={variante}
      data-cargando={cargando}
      disabled={cargando || resto.disabled}
      {...resto}
    >
      {cargando && <span className="girador" aria-hidden="true" />}
      {children}
    </button>
  );
}
