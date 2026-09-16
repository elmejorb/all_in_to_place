<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la hoja de factura necesita guardar y todavía no existía (docs/15).
 *
 * Cuatro decisiones:
 *
 *  - `fecha` es la fecha del documento, la que ve el cliente y se puede
 *    cambiar. `emitida_en` sigue siendo el sello de cuándo ocurrió de verdad.
 *    Son cosas distintas y mezclarlas destruye la bitácora: una factura
 *    fechada el día 1 puede haberse emitido el día 3, y las dos cosas son
 *    ciertas.
 *  - `vendedor` es texto libre, no un usuario. Quien vende en el mostrador no
 *    siempre tiene cuenta, y quien la teclea ya queda en `usuario_id`.
 *  - `referencia` es el número de orden de compra del cliente, que es lo que
 *    él busca cuando llama a preguntar.
 *  - `detalle` en el renglón es la descripción ampliada: el producto dice
 *    "Torta chocolate" y el detalle dice "con el nombre en letra azul".
 *
 * Y una regla que vuelve a la base: un borrador sí se puede borrar —nunca
 * existió para nadie más—, pero un documento emitido no. Antes el permiso
 * estaba revocado para todo; ahora se concede y una política restrictiva lo
 * limita a los borradores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documento', function (Blueprint $table) {
            $table->date('fecha')->nullable()->after('estado');
            $table->string('referencia', 60)->nullable()->after('folio');
            $table->string('vendedor', 120)->nullable()->after('cliente_exento');
        });

        Schema::table('documento_renglon', function (Blueprint $table) {
            $table->text('detalle')->nullable()->after('descripcion');
        });

        // Las facturas que ya existen llevan como fecha el día en que se emitieron.
        DB::statement('update documento set fecha = emitida_en::date where fecha is null and emitida_en is not null');

        // Borrar solo borradores.
        //
        // Se hace con un disparador y no con una política de RLS a propósito:
        // una política que no deja borrar no da error, simplemente borra cero
        // filas, y un intento de borrar una factura emitida tiene que reventar
        // a la vista de todos, no pasar en silencio.
        DB::statement('grant delete on documento to aiop_app');
        DB::statement(<<<'SQL'
            -- "or replace": al rehacer el esquema se caen las tablas, pero
            -- una función suelta sobrevive y volver a crearla fallaría.
            create or replace function documento_solo_se_borra_el_borrador() returns trigger as $$
            begin
                if old.estado <> 'borrador' then
                    raise exception 'Un documento emitido no se borra: se anula (FAC-09).';
                end if;
                return old;
            end;
            $$ language plpgsql;
        SQL);
        DB::statement(<<<'SQL'
            create trigger documento_no_borrar_emitidas
            before delete on documento
            for each row execute function documento_solo_se_borra_el_borrador();
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists documento_no_borrar_emitidas on documento');
        DB::statement('drop function if exists documento_solo_se_borra_el_borrador()');
        DB::statement('revoke delete on documento from aiop_app');

        Schema::table('documento_renglon', function (Blueprint $table) {
            $table->dropColumn('detalle');
        });

        Schema::table('documento', function (Blueprint $table) {
            $table->dropColumn(['fecha', 'referencia', 'vendedor']);
        });
    }
};
