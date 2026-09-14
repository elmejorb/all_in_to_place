const BASE = "http://localhost:8000";

/** Lee la cookie de CSRF propia de esta aplicación (SEG-26). */
function tokenCsrf(nombre: string): string {
  const par = document.cookie.split("; ").find((c) => c.startsWith(nombre + "="));
  return par ? decodeURIComponent(par.split("=")[1]) : "";
}

export class ErrorApi extends Error {
  constructor(
    public estado: number,
    public errores: Record<string, string[]> = {},
    mensaje = "Algo salió mal.",
  ) {
    super(mensaje);
  }

  /** Primer error de un campo, para mostrarlo junto a él. */
  campo(nombre: string): string | undefined {
    return this.errores[nombre]?.[0];
  }
}

export function crearCliente(prefijo: string, cookieCsrf: string) {
  let csrfListo = false;

  async function asegurarCsrf() {
    if (csrfListo) return;
    await fetch(`${BASE}${prefijo}/sesion/csrf`, { credentials: "include" });
    csrfListo = true;
  }

  async function pedir<T>(ruta: string, opciones: RequestInit = {}): Promise<T> {
    const metodo = (opciones.method ?? "GET").toUpperCase();
    if (metodo !== "GET") await asegurarCsrf();

    const respuesta = await fetch(`${BASE}${prefijo}${ruta}`, {
      ...opciones,
      credentials: "include",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        ...(metodo !== "GET" ? { "X-XSRF-TOKEN": tokenCsrf(cookieCsrf) } : {}),
        ...(opciones.headers ?? {}),
      },
    });

    if (respuesta.status === 204) return undefined as T;

    const cuerpo = await respuesta.json().catch(() => ({}));

    if (!respuesta.ok) {
      throw new ErrorApi(
        respuesta.status,
        cuerpo.errors ?? {},
        cuerpo.message ?? "No se pudo completar la operación.",
      );
    }

    return cuerpo as T;
  }

  return {
    get: <T>(ruta: string) => pedir<T>(ruta),
    post: <T>(ruta: string, datos?: unknown) =>
      pedir<T>(ruta, { method: "POST", body: JSON.stringify(datos ?? {}) }),
    borrar: <T>(ruta: string) => pedir<T>(ruta, { method: "DELETE" }),
  };
}

export const api = crearCliente("/v1/admin", "AIOP-CONSOLA-XSRF");

export type Estado = {
  autenticado: boolean;
  usuario?: { id: string; nombres: string; apellidos: string; email: string; rol: string };
};
