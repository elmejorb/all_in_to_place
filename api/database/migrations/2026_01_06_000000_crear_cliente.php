<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes (CLI-01).
 *
 * Dos campos que no son adorno y hoy no existen: la exención de impuesto con su
 * número de certificado —sin el número, la exención no se sostiene ante una
 * auditoría— y el límite de crédito, que es lo que decide si se puede fiar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();

            $table->string('nombre', 150);
            $table->string('tipo', 10)->default('persona');      // persona | empresa
            $table->string('identificacion', 40)->nullable();    // cédula, EIN, NIT según el país
            $table->string('telefono', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('direccion')->nullable();

            // Exención de impuesto: sin certificado no hay exención (CLI-01).
            $table->boolean('exento')->default(false);
            $table->string('certificado_exencion', 60)->nullable();

            $table->string('terminos_pago', 60)->nullable();
            $table->bigInteger('limite_credito_centavos')->default(0);   // 0 = sin crédito
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz('borrado_en');

            $table->index(['empresa_id', 'activo']);
            $table->index(['empresa_id', 'telefono']);
        });

        DB::statement('create index cliente_busqueda on cliente (empresa_id, lower(nombre))');

        // La identificación fiscal no se repite dentro de la empresa.
        DB::statement('create unique index cliente_identificacion_unica on cliente (empresa_id, lower(identificacion)) where identificacion is not null and borrado_en is null');

        DB::statement('alter table cliente enable row level security');

        DB::statement(<<<'SQL'
            create policy cliente_app on cliente for all to aiop_app
            using (empresa_id = app_empresa_id())
            with check (empresa_id = app_empresa_id());
        SQL);

        // Sin política para aiop_consola (ADM-08).
    }

    public function down(): void
    {
        DB::statement('drop policy if exists cliente_app on cliente');
        Schema::dropIfExists('cliente');
    }
};
