import type { ReactNode } from "react";

export function Aviso({ children, tono = "error" }: { children: ReactNode; tono?: "error" | "aviso" | "bien" }) {
  return (
    <p className="aviso" data-tono={tono} role="alert">
      {children}
    </p>
  );
}
