import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "@aiop/ui/tokens.css";
import "@aiop/ui/estructura.css";
import "./estilos.css";
import "./hoja-factura.css";
import { App } from "./App";

createRoot(document.getElementById("raiz")!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
