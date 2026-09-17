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
    /**
     * El cuerpo completo de la respuesta. Muchas respuestas traen más que un
     * mensaje —un código, o la lista de lo que falta en existencia— y sin esto
     * la pantalla no puede usarlo.
     */
    public cuerpo: Record<string, unknown> = {},
  ) {
    super(mensaje);
  }

  /** El código que da el servidor para distinguir un caso de otro. */
  get codigo(): string | undefined {
    return typeof this.cuerpo.codigo === "string" ? this.cuerpo.codigo : undefined;
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

    return new ErrorApi(this.estado, resto, this.message, this.cuerpo);
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
        cuerpo,
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
        throw new ErrorApi(respuesta.status, datos.errors ?? {}, datos.message ?? "No se pudo subir el archivo.", datos);
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

export type Cliente = {
  id: string;
  nombre: string;
  tipo: string;
  identificacion: string | null;
  telefono: string | null;
  email: string | null;
  direccion: string | null;
  exento: boolean;
  certificado_exencion: string | null;
  terminos_pago: string | null;
  limite_credito: string;
  tiene_credito: boolean;
  notas: string | null;
  activo: boolean;
};

export type ListadoClientes = Listado<Cliente> & { tipos: string[] };

/** Los datos de la empresa tal como salen impresos en la hoja de factura. */
export type Membrete = {
  id: string;
  nombre: string;
  nombre_legal: string;
  registro_comerciante: string | null;
  telefono: string | null;
  email: string | null;
  direccion: string | null;
  pais: string;
  moneda: string;
  logo: string | null;
  impuesto_desglose: { nombre: string; tasa: string }[];
  impuesto_tasa: string;
  serie: { nombre: string; proximo_folio: string };
};

export type RenglonCalculado = {
  bruto: string;
  descuento: string;
  base: string;
  impuesto: string;
  tasa: string;
  total: string;
};

export type Calculo = {
  subtotal: string;
  descuento: string;
  base: string;
  impuesto: string;
  total: string;
  desglose: { nombre: string; monto: string }[];
  renglones: RenglonCalculado[];
};

export type FacturaResumen = {
  id: string;
  folio: string | null;
  estado: string;
  cliente: string | null;
  fecha: string | null;
  emitida_en: string | null;
  vence_el: string | null;
  total: string;
  pagado: string;
  saldo: string;
};

export type FacturaDetalle = FacturaResumen & {
  subtotal: string;
  descuento: string;
  descuento_tipo: string | null;
  descuento_valor: string | null;
  base: string;
  impuesto: string;
  desglose: { nombre: string; monto: string }[];
  cliente_id: string | null;
  cliente_exento: boolean;
  vendedor: string | null;
  referencia: string | null;
  terminos_pago: string | null;
  notas: string | null;
  motivo_anulacion: string | null;
  emitida_por: string | null;
  /** Solo un borrador se reescribe; lo emitido se anula (FAC-09). */
  editable: boolean;
  renglones: {
    id: string;
    producto: string | null;
    descripcion: string;
    detalle: string | null;
    sku: string | null;
    cantidad: number;
    unidad: string;
    precio: string;
    tasa: string;
    descuento: string;
    descuento_tipo: string | null;
    descuento_valor: string | null;
    impuesto: string;
    total: string;
  }[];
  pagos: { id: string; metodo: string; monto: string; cambio: string; referencia: string | null; fecha: string | null }[];
};

export type ListadoFacturas = {
  datos: FacturaResumen[];
  siguiente: string | null;
  anterior: string | null;
  resumen: { cantidad: number; total: string; pagado: string; por_cobrar: string; borradores: number };
  metodos_pago: string[];
  permisos: { facturar: boolean; anular: boolean };
};

// --- hoja de cuadre (CAJ-11 a CAJ-14) ---------------------------------------

export type GastoCuadre = { id?: string; descripcion: string; monto: string };

export type CuadreResumen = {
  id: string;
  fecha: string;
  turno: string;
  efectivo_inicial: string;
  ventas_lectura: string;
  efectivo_cambio: string;
  total_efectivo: string;
  gastos: string;
  a_depositar: string;
  cuadro: string | null;
};

/** Lo escrito a mano frente a lo que el sistema facturó (CAJ-12). */
export type Comparado = {
  declarado: string;
  facturado: string;
  diferencia: string;
  cuadra: boolean;
};

export type CuadreDetalle = CuadreResumen & {
  tarjeta: string;
  ath_movil: string;
  venta_y_cambio: string;
  total_ventas: string;
  notas: string | null;
  gastos_detalle: GastoCuadre[];
  facturado: {
    facturas: number;
    ventas: string;
    tarjeta: string;
    ath_movil: string;
    efectivo: string;
  };
  comparacion: Record<string, Comparado>;
};

export type ListadoCuadres = {
  datos: CuadreResumen[];
  siguiente: string | null;
  anterior: string | null;
  resumen: { hojas: number; gastos: string; a_depositar: string; ventas: string };
  turnos: string[];
  /** El día y el turno de la empresa ahora mismo, no los del navegador. */
  hoy: string;
  turno_actual: string;
  permisos: { cuadrar: boolean; ver_todas: boolean };
};

export type FacturadoEnTurno = {
  /** Horas del turno ya en la zona de la empresa, como "00:00". */
  desde: string;
  hasta: string;
  facturas: number;
  ventas: string;
  tarjeta: string;
  ath_movil: string;
  efectivo: string;
};

/**
 * El dinero se suma en centavos enteros, nunca en coma flotante (ARQ-09).
 *
 * Esta aritmética repite la de `App\Domain\Cuadre` a propósito: son cuatro
 * sumas y pedirlas al servidor en cada tecla sería absurdo. El servidor sigue
 * siendo el que manda —recalcula al guardar y su respuesta pisa lo que hay en
 * pantalla—, así que una diferencia no puede sobrevivir a un guardado.
 */
export function aCentavos(texto: string): number {
  const limpio = texto.replace(/\s/g, "").replace(",", ".");
  if (limpio === "" || Number.isNaN(Number(limpio))) return 0;
  return Math.round(Number(limpio) * 100);
}

export const aTexto = (centavos: number) => (centavos / 100).toFixed(2);

/**
 * El dinero como se lee, con separador de miles.
 *
 * La API siempre habla en crudo ("154838.00") porque así se compara y se
 * exporta sin sorpresas; el separador es cosa de la pantalla. Sin él, una cifra
 * de seis dígitos hay que contarla con el dedo.
 */
export function enDinero(valor: string | number): string {
  const numero = typeof valor === "number" ? valor : Number(valor);
  if (Number.isNaN(numero)) return String(valor);

  return numero.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
