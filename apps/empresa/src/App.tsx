import { useCallback, useEffect, useState } from "react";
import {
  Aviso,
  Boton,
  Campo,
  Desplegable,
  Estructura,
  InterruptorTema,
  ProveedorAvisos,
  useAvisar,
  type Modulo,
} from "@aiop/ui";
import { api, ErrorApi, type EmpresaResumen, type Estado } from "./api";
import { Categorias } from "./pantallas/Categorias";
import { Productos } from "./pantallas/Productos";
import { Suplidores } from "./pantallas/Suplidores";

const MODULOS: Modulo[] = [
  { clave: "productos", titulo: "Productos", icono: "▣", grupo: "Inventario" },
  { clave: "suplidores", titulo: "Suplidores", icono: "⛬", grupo: "Inventario" },
  { clave: "categorias", titulo: "Categorías", icono: "☰", grupo: "Inventario" },
];

export function App() {
  return (
    <ProveedorAvisos>
      <Raiz />
    </ProveedorAvisos>
  );
}

function Raiz() {
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

  if (!estado) {
    return (
      <div className="acceso">
        <p className="vacio">Cargando...</p>
      </div>
    );
  }

  if (!estado.autenticado) return <Acceso alEntrar={setEstado} />;
  return <Aplicacion estado={estado} alCambiar={setEstado} />;
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
          <span className="marca__icono" aria-hidden="true">◧</span>
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
            onChange={(e) => {
              setEmail(e.target.value);
              setError((a) => a?.sinCampo("email") ?? null);
            }}
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

function Aplicacion({ estado, alCambiar }: { estado: Estado; alCambiar: (e: Estado) => void }) {
  const activa = estado.empresa_activa ?? null;
  const puedeVerCatalogo = activa?.permisos.includes("catalogo.ver") ?? false;
  const [seccion, setSeccion] = useState(activa && puedeVerCatalogo ? "productos" : "empresas");
  const avisar = useAvisar();

  async function salir() {
    await api.borrar("/sesion");
    alCambiar({ autenticado: false });
  }

  async function elegirEmpresa(empresa: EmpresaResumen) {
    if (empresa.id === activa?.id) return;
    const nuevo = await api.post<Estado>("/sesion/empresa", { empresa: empresa.id });
    alCambiar(nuevo);
    setSeccion(nuevo.empresa_activa?.permisos.includes("catalogo.ver") ? "productos" : "empresas");
    avisar(`Ahora estás trabajando en ${empresa.nombre}.`);
  }

  return (
    <Estructura
      marca="All in One Place"
      modulos={puedeVerCatalogo ? MODULOS : []}
      activo={seccion}
      alElegir={setSeccion}
      cabecera={
        <SelectorEmpresa
          estado={estado}
          activa={activa}
          alElegir={elegirEmpresa}
          alVerTodas={() => setSeccion("empresas")}
        />
      }
      acciones={
        <>
          <InterruptorTema />
          <MenuUsuario estado={estado} alSalir={salir} />
        </>
      }
    >
      {activa?.solo_lectura && (
        <Aviso tono="aviso">
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
        <PantallaEmpresas estado={estado} activa={activa} alElegir={elegirEmpresa} />
      )}
    </Estructura>
  );
}

function iniciales(nombre: string): string {
  // Sin acentos: "PÁ" se lee como un error de codificación, "PA" no.
  const limpio = nombre.normalize("NFD").replace(/[̀-ͯ]/g, "");
  const partes = limpio.split(/\s+/).filter((p) => p.length > 2);
  const letras = partes.slice(0, 2).map((p) => p[0]?.toUpperCase() ?? "").join("");
  return letras || limpio.slice(0, 2).toUpperCase();
}

/** Selector de empresa con búsqueda a partir de cinco (EMP-05). */
function SelectorEmpresa({
  estado,
  activa,
  alElegir,
  alVerTodas,
}: {
  estado: Estado;
  activa: EmpresaResumen | null;
  alElegir: (e: EmpresaResumen) => Promise<void>;
  alVerTodas: () => void;
}) {
  const [filtro, setFiltro] = useState("");
  const empresas = estado.empresas ?? [];
  const conBuscador = empresas.length >= 5;

  const visibles = filtro.trim()
    ? empresas.filter((e) => e.nombre.toLowerCase().includes(filtro.trim().toLowerCase()))
    : empresas;

  return (
    <Desplegable
      titulo="Cambiar de empresa"
      etiqueta={
        <>
          <span className="inicial" aria-hidden="true">{iniciales(activa?.nombre ?? "??")}</span>
          <span className="celda-principal">
            <b>{activa?.nombre ?? "Elige una empresa"}</b>
            {activa && <small>{activa.rol}{activa.perfil ? ` · ${activa.perfil}` : ""}</small>}
          </span>
        </>
      }
    >
      {(cerrar) => (
        <>
          <p className="menu__titulo">Empresas</p>

          {conBuscador && (
            <input
              className="menu__buscador"
              type="search"
              placeholder="Buscar empresa"
              aria-label="Buscar empresa"
              value={filtro}
              onChange={(e) => setFiltro(e.target.value)}
            />
          )}

          {visibles.map((empresa) => (
            <button
              key={empresa.id}
              type="button"
              className="menu__item"
              aria-current={empresa.id === activa?.id}
              onClick={() => {
                void alElegir(empresa);
                cerrar();
              }}
            >
              <span className="inicial" aria-hidden="true">{iniciales(empresa.nombre)}</span>
              <span>
                {empresa.nombre}
                <small>
                  {empresa.rol} · {empresa.pais} · {empresa.moneda}
                  {empresa.solo_lectura ? " · solo lectura" : ""}
                </small>
              </span>
            </button>
          ))}

          {visibles.length === 0 && <p className="menu__item vacio">Ninguna empresa coincide.</p>}

          <div className="menu__separador" />
          <button
            type="button"
            className="menu__item"
            onClick={() => {
              alVerTodas();
              cerrar();
            }}
          >
            Ver todas mis empresas
          </button>
        </>
      )}
    </Desplegable>
  );
}

function MenuUsuario({ estado, alSalir }: { estado: Estado; alSalir: () => Promise<void> }) {
  const usuario = estado.usuario;
  const nombre = `${usuario?.nombres ?? ""} ${usuario?.apellidos ?? ""}`.trim();

  return (
    <Desplegable
      alineado="derecha"
      titulo="Tu cuenta"
      etiqueta={<span className="inicial" aria-hidden="true">{iniciales(nombre || "??")}</span>}
    >
      {() => (
        <>
          <p className="menu__titulo">Tu cuenta</p>
          <div className="menu__item" data-estatico="true">
            <span>
              {nombre}
              <small>{usuario?.email}</small>
            </span>
          </div>
          <div className="menu__separador" />
          <button type="button" className="menu__item" data-tono="peligro" onClick={() => void alSalir()}>
            Cerrar sesión
          </button>
        </>
      )}
    </Desplegable>
  );
}

function PantallaEmpresas({
  estado,
  activa,
  alElegir,
}: {
  estado: Estado;
  activa: EmpresaResumen | null;
  alElegir: (e: EmpresaResumen) => Promise<void>;
}) {
  const [cambiando, setCambiando] = useState<string | null>(null);
  const empresas = estado.empresas ?? [];

  return (
    <section className="pantalla">
      <div className="pantalla__cabeza">
        <div>
          <h1>{activa ? "Mis empresas" : "Elige una empresa"}</h1>
          <p>
            {activa
              ? "Puedes cambiar de empresa cuando quieras; la sesión se renueva al hacerlo."
              : "Tienes acceso a varias empresas. Elige con cuál vas a trabajar."}
          </p>
        </div>
      </div>

      <div className="empresas">
        {empresas.map((empresa) => (
          <button
            key={empresa.id}
            type="button"
            className="empresa"
            data-activa={empresa.id === activa?.id}
            disabled={cambiando !== null}
            onClick={async () => {
              setCambiando(empresa.id);
              try {
                await alElegir(empresa);
              } finally {
                setCambiando(null);
              }
            }}
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
