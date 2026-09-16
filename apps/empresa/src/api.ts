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

  /**
   * Devuelve el error sin el campo indicado, o null si ya no queda ninguno.
   * Se usa al escribir: un mensaje que sigue ahí después de corregir el campo
   * confunde más de lo que ayuda (PR-08).
   */
  sinCampo(nombre: string): ErrorApi | null {
    if (!(nombre in this.errores)) return this;

    const { [nombre]: _quitado, ...resto } = this.errores;
    if (Object.keys(resto).length === 0) return null;

    return new ErrorApi(this.estado, resto, this.message);
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
    put: <T>(ruta: string, datos?: unknown) =>
      pedir<T>(ruta, { method: "PUT", body: JSON.stringify(datos ?? {}) }),
    borrar: <T>(ruta: string, datos?: unknown) =>
      pedir<T>(ruta, { method: "DELETE", body: datos ? JSON.stringify(datos) : undefined }),

    /** Envía un archivo. No lleva Content-Type: lo pone el navegador con su frontera. */
    subir: async <T>(ruta: string, cuerpo: FormData): Promise<T> => {
      await asegurarCsrf();

      const respuesta = await fetch(`${BASE}${prefijo}${ruta}`, {
        method: "POST",
        credentials: "include",
        body: cuerpo,
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-XSRF-TOKEN": tokenCsrf(cookieCsrf),
        },
      });

      const datos = await respuesta.json().catch(() => ({}));

      if (!respuesta.ok) {
        throw new ErrorApi(respuesta.status, datos.errors ?? {}, datos.message ?? "No se pudo subir el archivo.");
      }

      return datos as T;
    },

    /** Descarga un archivo del servidor conservando el nombre que este propone. */
    descargar: async (ruta: string): Promise<void> => {
      const respuesta = await fetch(`${BASE}${prefijo}${ruta}`, {
        credentials: "include",
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });

      if (!respuesta.ok) {
        const datos = await respuesta.json().catch(() => ({}));
        throw new ErrorApi(respuesta.status, datos.errors ?? {}, datos.message ?? "No se pudo descargar el archivo.");
      }

      const nombre = respuesta.headers.get("X-Nombre-Archivo") ?? "archivo.csv";
      const contenido = await respuesta.blob();
      const url = URL.createObjectURL(contenido);
      const enlace = document.createElement("a");
      enlace.href = url;
      enlace.download = nombre;
      document.body.appendChild(enlace);
      enlace.click();
      enlace.remove();
      URL.revokeObjectURL(url);
    },
  };
}

export const api = crearCliente("/v1", "AIOP-EMPRESA-XSRF");

export type EmpresaResumen = {
  id: string;
  nombre: string;
  pais: string;
  moneda: string;
  estado: string;
  solo_lectura: boolean;
  rol: string;
  perfil: string | null;
  permisos: string[];
};

export type Estado = {
  autenticado: boolean;
  usuario?: { id: string; nombres: string; apellidos: string; email: string };
  empresa_activa?: EmpresaResumen | null;
  empresas?: EmpresaResumen[];
};

export type Suplidor = {
  id: string;
  razon_social: string;
  nombre_comercial: string | null;
  numero_cliente: string | null;
  telefono: string | null;
  email: string | null;
  vendedor: string | null;
  terminos_pago: string | null;
  notas: string | null;
  activo: boolean;
};

export type Listado<T> = {
  datos: T[];
  siguiente: string | null;
  anterior: string | null;
  total_visible: number;
  permisos: { editar: boolean; desactivar: boolean };
};

export type Categoria = {
  id: string;
  nombre: string;
  descripcion: string | null;
  color: string;
  productos?: number;
};

export type ListadoCategorias = Listado<Categoria> & { colores: string[] };

export type Producto = {
  id: string;
  nombre: string;
  sku: string | null;
  codigo_barras: string | null;
  descripcion: string | null;
  unidad: string;
  precio: string;
  impuesto: string;
  es_servicio: boolean;
  activo: boolean;
  existencia: number | null;
  existencia_minima: number | null;
  estado_existencia: string | null;
  categoria: { id: string; nombre: string; color: string } | null;
  suplidor: { id: string; nombre: string } | null;
  // Solo para roles que ven costos (matriz del documento 04).
  costo?: string;
  margen?: number | null;
  vende_bajo_costo?: boolean;
};

export type ListadoProductos = Listado<Producto> & {
  unidades: string[];
  motivos_ajuste: string[];
  permisos: { editar: boolean; desactivar: boolean; ver_costos: boolean };
};

export const descargar = (ruta: string) => api.descargar(ruta);

export type FilaImportada = {
  numero: number;
  accion: "nuevo" | "actualiza" | "error";
  nombre: string;
  sku: string | null;
  errores: string[];
};

export type Importacion = {
  id: string;
  archivo: string;
  estado: string;
  actualizar_existentes: boolean;
  total: number;
  nuevas: number;
  actualiza: number;
  errores: number;
  puede_aplicarse: boolean;
  filas: FilaImportada[];
};
