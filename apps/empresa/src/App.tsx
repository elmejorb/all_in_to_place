import { useCallback, useEffect, useState } from "react";
import { Aviso, Boton, Campo } from "@aiop/ui";
import { api, ErrorApi, type EmpresaResumen, type Estado } from "./api";
import { Categorias } from "./pantallas/Categorias";
import { Productos } from "./pantallas/Productos";
import { Suplidores } from "./pantallas/Suplidores";

export function App() {
  const [estado, setEstado] = useState<Estado | null>(null);

  const cargar = useCallback(async () => {
    try {
      setEstado(await api.get<Estado>("/sesion"));
    } catch {
      setEstado({ autenticado: false });
    }
  }, []);

  useEffect(() => {
    void cargar();
  }, [cargar]);

  if (!estado) return <Cargando />;
  if (!estado.autenticado) return <Acceso alEntrar={setEstado} />;
  return <Aplicacion estado={estado} alCambiar={setEstado} />;
}

function Cargando() {
  return (
    <div className="acceso">
      <p className="vacio">Cargando...</p>
    </div>
  );
}

function Acceso({ alEntrar }: { alEntrar: (e: Estado) => void }) {
  const [email, setEmail] = useState("");
  const [clave, setClave] = useState("");
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorApi | null>(null);

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    setEnviando(true);
    setError(null);

    try {
      alEntrar(await api.post<Estado>("/sesion", { email, password: clave }));
    } catch (e) {
      setError(e as ErrorApi);
    } finally {
      setEnviando(false);
    }
  }

  const errorGeneral = error && !error.campo("email") && !error.campo("password") ? error.message : null;

  return (
    <main className="acceso">
      <div className="tarjeta acceso__caja">
        <div className="marca">
          <span className="marca__icono" aria-hidden="true">AI</span>
          All in One Place
        </div>

        <div>
          <h1>Entra a tu empresa</h1>
          <p className="sub">Inventario, facturación y cuadre de caja.</p>
        </div>

        {errorGeneral && <Aviso>{errorGeneral}</Aviso>}

        <form onSubmit={enviar} noValidate>
          <Campo
            id="email"
            etiqueta="Correo"
            type="email"
            autoComplete="username"
            autoFocus
            required
            value={email}
            error={error?.campo("email")}
            onChange={(e) => setEmail(e.target.value)}
          />
          <Campo
            id="clave"
            etiqueta="Contraseña"
            type="password"
            autoComplete="current-password"
            required
            value={clave}
            error={error?.campo("password")}
            onChange={(e) => setClave(e.target.value)}
          />
          <Boton type="submit" cargando={enviando}>
            {enviando ? "Entrando..." : "Entrar"}
          </Boton>
        </form>

        <p className="pie">
          ¿Olvidaste tu contraseña? Escríbele a quien administra la cuenta de tu empresa.
        </p>
      </div>
    </main>
  );
}

type Seccion = "productos" | "suplidores" | "categorias" | "empresas";

function Aplicacion({ estado, alCambiar }: { estado: Estado; alCambiar: (e: Estado) => void }) {
  const activa = estado.empresa_activa ?? null;
  const puedeVerCatalogo = activa?.permisos.includes("catalogo.ver") ?? false;
  const [seccion, setSeccion] = useState<Seccion>(activa && puedeVerCatalogo ? "productos" : "empresas");

  async function salir() {
    await api.borrar("/sesion");
    alCambiar({ autenticado: false });
  }

  return (
    <div className="panel">
      <header className="tarjeta barra">
        <div className="barra__usuario">
          <b>{estado.usuario?.nombres} {estado.usuario?.apellidos}</b>
          <span>{activa ? activa.nombre : estado.usuario?.email}</span>
        </div>

        <nav className="nav" aria-label="Secciones">
          {puedeVerCatalogo && (
            <>
              <button
                type="button"
                aria-current={seccion === "productos" ? "page" : undefined}
                onClick={() => setSeccion("productos")}
              >
                Productos
              </button>
              <button
                type="button"
                aria-current={seccion === "suplidores" ? "page" : undefined}
                onClick={() => setSeccion("suplidores")}
              >
                Suplidores
              </button>
              <button
                type="button"
                aria-current={seccion === "categorias" ? "page" : undefined}
                onClick={() => setSeccion("categorias")}
              >
                Categorías
              </button>
            </>
          )}
          <button
            type="button"
            aria-current={seccion === "empresas" ? "page" : undefined}
            onClick={() => setSeccion("empresas")}
          >
            {(estado.empresas?.length ?? 0) > 1 ? "Cambiar empresa" : "Mi empresa"}
          </button>
        </nav>

        <Boton variante="suave" onClick={salir}>Cerrar sesión</Boton>
      </header>

      {activa?.solo_lectura && (
        <Aviso>
          Esta empresa está {activa.estado}. Puedes consultar y exportar tus datos, pero no registrar
          movimientos nuevos.
        </Aviso>
      )}

      {seccion === "productos" && activa && puedeVerCatalogo ? (
        <Productos soloLectura={activa.solo_lectura} />
      ) : seccion === "suplidores" && activa && puedeVerCatalogo ? (
        <Suplidores soloLectura={activa.solo_lectura} />
      ) : seccion === "categorias" && activa && puedeVerCatalogo ? (
        <Categorias soloLectura={activa.solo_lectura} />
      ) : (
        <SelectorEmpresas estado={estado} alCambiar={alCambiar} alElegir={() => setSeccion(puedeVerCatalogo ? "productos" : "empresas")} />
      )}
    </div>
  );
}

function SelectorEmpresas({
  estado,
  alCambiar,
  alElegir,
}: {
  estado: Estado;
  alCambiar: (e: Estado) => void;
  alElegir: () => void;
}) {
  const [cambiando, setCambiando] = useState<string | null>(null);
  const empresas = estado.empresas ?? [];
  const activa = estado.empresa_activa ?? null;

  async function elegir(empresa: EmpresaResumen) {
    if (empresa.id === activa?.id) return;
    setCambiando(empresa.id);
    try {
      const nuevo = await api.post<Estado>("/sesion/empresa", { empresa: empresa.id });
      alCambiar(nuevo);
      alElegir();
    } finally {
      setCambiando(null);
    }
  }

  return (
    <section>
      <h1 className="titulo">{activa ? "Empresa activa" : "Elige una empresa"}</h1>
      <p className="vacio subtitulo">
        {activa
          ? "Estás trabajando en esta empresa. Puedes cambiar cuando quieras."
          : "Tienes acceso a varias empresas. Elige con cuál vas a trabajar."}
      </p>

      <div className="empresas">
        {empresas.map((empresa) => (
          <button
            key={empresa.id}
            type="button"
            className="empresa"
            data-activa={empresa.id === activa?.id}
            onClick={() => elegir(empresa)}
            disabled={cambiando !== null}
          >
            <b>{empresa.nombre}</b>
            <small>
              {empresa.rol}
              {empresa.perfil ? " · " + empresa.perfil : ""} · {empresa.pais} · {empresa.moneda}
            </small>
            {empresa.id === activa?.id && <span className="etiqueta" data-tono="marca">Actual</span>}
            {empresa.solo_lectura && <span className="etiqueta" data-tono="aviso">Solo lectura</span>}
            {cambiando === empresa.id && <small>Cambiando...</small>}
          </button>
        ))}
      </div>
    </section>
  );
}
