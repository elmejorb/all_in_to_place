<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aislamiento por empresa en la propia base de datos (ARQ-04).
 *
 * Tres roles, tres alcances:
 *
 *  - aiop_app      solo ve la empresa de la sesión. Si nadie fijó el contexto,
 *                  no ve nada: el filtro compara contra NULL y no devuelve filas.
 *  - aiop_consola  administra cuentas: ve empresa, membresía, usuario y los
 *                  usuarios de plataforma. Sobre las tablas de negocio no se le
 *                  crea ninguna política, así que el motor le niega esos datos
 *                  aunque la consulta lo pida (ADM-08).
 *  - aiop_migrator dueña del esquema. No la usa la aplicación en runtime (SEG-38).
 *
 * El contexto lo fija la aplicación con set_config sobre la conexión, nunca con
 * un parámetro que venga del navegador (ARQ-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Funciones de contexto: leen la variable de sesión y devuelven NULL si no está fijada.
        DB::statement(<<<'SQL'
            create or replace function app_empresa_id() returns bigint
            language sql stable as $$
                select nullif(current_setting('app.empresa_id', true), '')::bigint
            $$;
        SQL);

        DB::statement(<<<'SQL'
            create or replace function app_usuario_id() returns bigint
            language sql stable as $$
                select nullif(current_setting('app.usuario_id', true), '')::bigint
            $$;
        SQL);

        DB::statement(<<<'SQL'
            create or replace function app_login_email() returns text
            language sql stable as $$
                select nullif(current_setting('app.login_email', true), '')
            $$;
        SQL);

        foreach (['app_empresa_id', 'app_usuario_id', 'app_login_email'] as $fn) {
            DB::statement("grant execute on function {$fn}() to aiop_app, aiop_consola");
        }

        // --- empresa ---------------------------------------------------------
        DB::statement('alter table empresa enable row level security');

        // La app ve la empresa activa, y las empresas donde el usuario tiene
        // membresía, para poder pintar el selector (EMP-05).
        DB::statement(<<<'SQL'
            create policy empresa_app on empresa for select to aiop_app
            using (
                id = app_empresa_id()
                or exists (
                    select 1 from membresia m
                    where m.empresa_id = empresa.id
                      and m.usuario_id = app_usuario_id()
                      and m.activa
                )
            );
        SQL);

        // Editar los datos de la empresa: solo la empresa activa (EMP-01).
        DB::statement(<<<'SQL'
            create policy empresa_app_editar on empresa for update to aiop_app
            using (id = app_empresa_id())
            with check (id = app_empresa_id());
        SQL);

        DB::statement('create policy empresa_consola on empresa for all to aiop_consola using (true) with check (true)');

        // --- membresia -------------------------------------------------------
        DB::statement('alter table membresia enable row level security');

        DB::statement(<<<'SQL'
            create policy membresia_app on membresia for select to aiop_app
            using (
                empresa_id = app_empresa_id()
                or usuario_id = app_usuario_id()
            );
        SQL);

        DB::statement('create policy membresia_consola on membresia for all to aiop_consola using (true) with check (true)');

        // --- usuario ---------------------------------------------------------
        DB::statement('alter table usuario enable row level security');

        // Tres motivos legítimos para leer un usuario desde la app:
        // el propio, un compañero de la empresa activa, o la fila que se está
        // autenticando en ese instante (una sola, la del correo del intento).
        DB::statement(<<<'SQL'
            create policy usuario_app on usuario for select to aiop_app
            using (
                id = app_usuario_id()
                or email = app_login_email()
                or exists (
                    select 1 from membresia m
                    where m.usuario_id = usuario.id
                      and m.empresa_id = app_empresa_id()
                      and m.activa
                )
            );
        SQL);

        DB::statement(<<<'SQL'
            create policy usuario_app_propio on usuario for update to aiop_app
            using (id = app_usuario_id())
            with check (id = app_usuario_id());
        SQL);

        DB::statement('create policy usuario_consola on usuario for all to aiop_consola using (true) with check (true)');

        // --- usuario_plataforma ---------------------------------------------
        // Sin política para aiop_app: la app de empresa no puede ni contar los
        // usuarios de la consola (ARQ-03).
        DB::statement('alter table usuario_plataforma enable row level security');

        DB::statement(<<<'SQL'
            create policy usuario_plataforma_consola on usuario_plataforma for all to aiop_consola
            using (
                id = app_usuario_id()
                or email = app_login_email()
                or true
            )
            with check (true);
        SQL);
    }

    public function down(): void
    {
        foreach (['empresa', 'membresia', 'usuario', 'usuario_plataforma'] as $tabla) {
            DB::statement("alter table {$tabla} disable row level security");
        }

        foreach ([
            'empresa_app on empresa',
            'empresa_app_editar on empresa',
            'empresa_consola on empresa',
            'membresia_app on membresia',
            'membresia_consola on membresia',
            'usuario_app on usuario',
            'usuario_app_propio on usuario',
            'usuario_consola on usuario',
            'usuario_plataforma_consola on usuario_plataforma',
        ] as $politica) {
            DB::statement("drop policy if exists {$politica}");
        }

        foreach (['app_empresa_id', 'app_usuario_id', 'app_login_email'] as $fn) {
            DB::statement("drop function if exists {$fn}()");
        }
    }
};
