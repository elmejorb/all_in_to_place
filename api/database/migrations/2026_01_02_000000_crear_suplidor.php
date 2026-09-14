<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suplidores (SUP-01).
 *
 * Los campos van separados y bien nombrados a propósito: en el sistema actual el
 * nombre del contacto aparece bajo "Nombre Empresa". Aquí razón social, nombre
 * comercial y vendedor son tres cosas distintas.
 *
 * Es además la primera tabla de negocio, así que fija el patrón de aislamiento
 * para todas las que vienen: política para aiop_app filtrando por empresa, y
 * ninguna política para aiop_consola, de modo que la consola no puede leer datos
 * de clientes ni queriendo (ADM-08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suplidor', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();

            $table->string('razon_social', 150);
            $table->string('nombre_comercial', 150)->nullable();
            $table->string('numero_cliente', 60)->nullable();   // el que el suplidor nos asignó
            $table->string('telefono', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('vendedor', 120)->nullable();        // persona de contacto
            $table->string('terminos_pago', 60)->nullable();
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);           // SUP-04: desactivar, no borrar

            $table->timestampsTz();
            $table->softDeletesTz('borrado_en');

            $table->index(['empresa_id', 'activo']);
            $table->index(['empresa_id', 'razon_social']);
        });

        // SUP-02: el número de cliente es único dentro de la empresa, no global.
        DB::statement('create unique index suplidor_numero_cliente_unico on suplidor (empresa_id, numero_cliente) where numero_cliente is not null and borrado_en is null');

        // Búsqueda por texto sin distinguir mayúsculas ni acentos (SUP-03).
        DB::statement('create index suplidor_busqueda on suplidor (empresa_id, lower(razon_social))');

        // --- aislamiento: el patrón de toda tabla de negocio ------------------
        DB::statement('alter table suplidor enable row level security');

        DB::statement(<<<'SQL'
            create policy suplidor_app on suplidor for all to aiop_app
            using (empresa_id = app_empresa_id())
            with check (empresa_id = app_empresa_id());
        SQL);

        // Sin política para aiop_consola. No es un olvido: es el requisito ADM-08.
    }

    public function down(): void
    {
        DB::statement('drop policy if exists suplidor_app on suplidor');
        Schema::dropIfExists('suplidor');
    }
};
