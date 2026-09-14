import type { InputHTMLAttributes } from "react";

type Props = InputHTMLAttributes<HTMLInputElement> & {
  id: string;
  etiqueta: string;
  error?: string;
};

/** Campo con etiqueta asociada y error junto al campo (RNF-04, PR-08). */
export function Campo({ id, etiqueta, error, ...resto }: Props) {
  return (
    <div className="campo" data-invalido={error ? "true" : "false"}>
      <label htmlFor={id}>{etiqueta}</label>
      <input id={id} aria-invalid={error ? true : undefined} aria-describedby={error ? id + "-error" : undefined} {...resto} />
      {error && <span className="error" id={id + "-error"} role="alert">{error}</span>}
    </div>
  );
}
