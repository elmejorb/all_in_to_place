import type { ReactNode } from "react";

export function Aviso({ children }: { children: ReactNode }) {
  return <p className="aviso" role="alert">{children}</p>;
}
