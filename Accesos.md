
Servicio	Dirección	Estado
PostgreSQL 17.6	127.0.0.1:5433	aceptando conexiones
API (Laravel)	http://127.0.0.1:8000	200
App de empresa	http://localhost:5173	200
Consola	        http://localhost:5174	200
Para probar en la app de empresa (contraseña Clave.Segura.1 en todos):

pedro@elalamo.test      — una sola empresa, entra directo al panel
luis@elalamo.test       — dos empresas, aparece el selector y puedes cambiar entre ellas
carlos@innovacion.test  — empresa suspendida, entra en modo solo lectura con el aviso
sinempresa@aiop.test    — sin membresía, no debe dejarte entrar
inactivo@elalamo.test   — usuario desactivado, tampoco entra
En la consola: laura@aiop.test (superadmin) o soporte@aiop.test.

Dos cosas que vale la pena que compruebes tú mismo: si intentas entrar a la consola con pedro@elalamo.test te rechaza —son ámbitos de autenticación separados—, y si abres las dos apps a la vez en el mismo navegador, cada una mantiene su sesión sin pisar la otra.