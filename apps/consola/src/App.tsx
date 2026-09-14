import { useCallback, useEffect, useState } from "react";
import { Aviso, Boton, Campo, InterruptorTema } from "@aiop/ui";
import { api, ErrorApi, type Estado } from "./api";

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

  if (!estado) {
    return (
      <div className="acceso">
        <p className="vacio">Cargando...</p>
      </div>
    );
  }

  if (!estado.autenticado) return <Acceso alEntrar={setEstado} />;
  return <Panel estado={estado} alSalir={() => setEstado({ autenticado: false })} />;
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

  const errorGeneral = error && !error.campo("email") ? error.message : null;

  return (
    <main className="acceso">
      <div className="tarjeta acceso__caja">
        <div className="marca">
          <span className="marca__icono" aria-hidden="true">AP</span>
          AIOP Consola
        </div>

        <div>
          <h1>Administración de la plataforma</h1>
          <p className="sub">Cuentas, planes y soporte. Aquí no se opera ninguna empresa.</p>
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
          El acceso de plataforma es independiente del de las empresas: una sesión de aquí no sirve
          allá.
        </p>
      </div>
    </main>
  );
}

function Panel({ estado, alSalir }: { estado: Estado; alSalir: () => void }) {
  async function salir() {
    await api.borrar("/sesion");
    alSalir();
  }

  return (
    <main className="contenido">
      <header className="barra-superior">
        <div className="celda-principal">
          <b>{estado.usuario?.nombres} {estado.usuario?.apellidos}</b>
          <small>{estado.usuario?.email} · {estado.usuario?.rol}</small>
        </div>
        <div className="barra-superior__acciones">
          <InterruptorTema />
          <Boton variante="suave" onClick={salir}>Cerrar sesión</Boton>
        </div>
      </header>

      <section className="tarjeta" style={{ padding: "1rem 1.25rem" }}>
        <h1 style={{ fontSize: "var(--t-medio)", marginBottom: "0.25rem" }}>Consola en construcción</h1>
        <p className="vacio sin-margen">
          Aquí van Empresas, Usuarios, Roles y Métodos de Pago. La sesión y el aislamiento ya están
          en pie: esta aplicación usa un rol de base de datos sin política sobre las tablas de
          negocio, así que no puede ver datos de ninguna empresa.
        </p>
      </section>
    </main>
  );
}
