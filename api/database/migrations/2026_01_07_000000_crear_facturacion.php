<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facturación: series, documentos, renglones y pagos.
 *
 * Tres decisiones que aguantan el resto del módulo:
 *
 *  - La numeración vive en `serie_documento` y se incrementa dentro de la misma
 *    transacción que inserta el documento, con la fila bloqueada. Así no hay
 *    huecos ni repetidos aunque dos cajeros emitan a la vez (ARQ-08).
 *  - El desglose del impuesto es configuración de la empresa, no código: en
 *    Puerto Rico son estatal y municipal; en otro país puede ser uno solo con
 *    otro nombre (FAC-04).
 *  - Un documento emitido no se borra ni se edita: se anula (FAC-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cómo se reparte el impuesto que cobra esta empresa (FAC-04).
        Schema::table('empresa', function (Blueprint $table) {
            $table->jsonb('impuesto_desglose')->nullable();
        });

        Schema::create('serie_documento', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();

            $table->string('tipo', 20);              // factura | cotizacion | nota_credito
            $table->string('nombre', 60);
            $table->string('prefijo', 12)->nullable();
            $table->integer('proximo_numero')->default(1);
            $table->boolean('predeterminada')->default(false);

            $table->timestampsTz();

            $table->unique(['empresa_id', 'tipo', 'nombre']);
        });

        Schema::create('documento', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();
            $table->foreignId('serie_id')->nullable()->constrained('serie_documento')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('cliente')->nullOnDelete();

            $table->string('tipo', 20)->default('factura');
            // borrador | emitida | pagada_parcial | pagada | vencida | anulada.
            // El estado se calcula, no se elige a mano (FAC-02).
            $table->string('estado', 20)->default('borrador');

            $table->integer('numero')->nullable();          // null mientras es borrador
            $table->string('folio', 30)->nullable();        // prefijo + número, lo que ve el cliente

            // El nombre del cliente se copia al emitir: si luego lo renombran,
            // la factura tiene que seguir diciendo lo que decía.
            $table->string('cliente_nombre', 150)->nullable();
            $table->boolean('cliente_exento')->default(false);

            $table->string('descuento_tipo', 12)->nullable();     // monto | porcentaje
            $table->string('descuento_valor', 20)->nullable();

            $table->bigInteger('subtotal_centavos')->default(0);
            $table->bigInteger('descuento_centavos')->default(0);
            $table->bigInteger('base_centavos')->default(0);
            $table->bigInteger('impuesto_centavos')->default(0);
            $table->bigInteger('total_centavos')->default(0);
            $table->bigInteger('pagado_centavos')->default(0);

            // Cómo quedó repartido el impuesto en esta factura concreta.
            $table->jsonb('impuesto_desglose')->nullable();

            $table->string('terminos_pago', 60)->nullable();
            $table->date('vence_el')->nullable();
            $table->text('notas')->nullable();

            $table->foreignId('usuario_id')->nullable()->constrained('usuario')->nullOnDelete();
            $table->timestampTz('emitida_en')->nullable();
            $table->timestampTz('anulada_en')->nullable();
            $table->string('motivo_anulacion', 200)->nullable();

            $table->timestampsTz();

            $table->unique(['empresa_id', 'serie_id', 'numero']);
            $table->index(['empresa_id', 'estado', 'id']);
            $table->index(['empresa_id', 'cliente_id']);
        });

        Schema::create('documento_renglon', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();
            $table->foreignId('documento_id')->constrained('documento')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('producto')->nullOnDelete();

            $table->integer('orden')->default(0);
            // La descripción se copia: la factura no cambia si el producto se renombra.
            $table->string('descripcion', 200);
            $table->string('sku', 60)->nullable();
            $table->string('unidad', 20)->default('unidad');
            $table->boolean('es_servicio')->default(false);

            $table->decimal('cantidad', 14, 3);
            $table->bigInteger('precio_centavos');
            $table->string('descuento_tipo', 12)->nullable();
            $table->string('descuento_valor', 20)->nullable();
            $table->integer('impuesto_milesimas')->default(0);
            $table->boolean('exento')->default(false);

            $table->bigInteger('bruto_centavos')->default(0);
            $table->bigInteger('descuento_centavos')->default(0);
            $table->bigInteger('base_centavos')->default(0);
            $table->bigInteger('impuesto_centavos')->default(0);
            $table->bigInteger('total_centavos')->default(0);

            $table->index(['documento_id', 'orden']);
        });

        Schema::create('pago', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();
            $table->foreignId('documento_id')->constrained('documento')->cascadeOnDelete();

            $table->string('metodo', 30);                    // efectivo, ath_movil, tarjeta...
            $table->bigInteger('monto_centavos');
            $table->bigInteger('recibido_centavos')->nullable();   // para calcular el cambio
            $table->string('referencia', 60)->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('usuario')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['empresa_id', 'documento_id']);
        });

        foreach (['serie_documento', 'documento', 'documento_renglon', 'pago'] as $tabla) {
            DB::statement("alter table {$tabla} enable row level security");
            DB::statement(<<<SQL
                create policy {$tabla}_app on {$tabla} for all to aiop_app
                using (empresa_id = app_empresa_id())
                with check (empresa_id = app_empresa_id());
            SQL);
            // Sin política para aiop_consola (ADM-08).
        }

        // Una factura emitida no se borra: se anula (FAC-09). Y un pago tampoco
        // se edita. Lo impide la base, no solo el código.
        DB::statement('revoke delete on documento from aiop_app');
        DB::statement('revoke update, delete on pago from aiop_app');
    }

    public function down(): void
    {
        foreach (['pago', 'documento_renglon', 'documento', 'serie_documento'] as $tabla) {
            DB::statement("drop policy if exists {$tabla}_app on {$tabla}");
            Schema::dropIfExists($tabla);
        }

        Schema::table('empresa', function (Blueprint $table) {
            $table->dropColumn('impuesto_desglose');
        });
    }
};
