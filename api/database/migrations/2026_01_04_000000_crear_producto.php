<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Productos y servicios (PRO-01) y sus movimientos de inventario (INV-01).
 *
 * Dos cosas que el sistema actual no tiene y aquí son el centro:
 *
 *  - Precio de venta. Hoy solo hay costo, así que no se puede saber cuánto se
 *    gana con nada (PRO-02).
 *  - La existencia no es un campo que se teclea: es la suma de los movimientos
 *    (INV-02). La columna `existencia` es una caché que solo escribe el
 *    registrador de movimientos, y siempre se puede recalcular desde el origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();

            $table->string('nombre', 150);
            $table->string('sku', 60)->nullable();              // código interno
            $table->string('codigo_barras', 60)->nullable();
            $table->string('descripcion', 500)->nullable();

            $table->foreignId('categoria_id')->nullable()->constrained('categoria')->nullOnDelete();
            $table->foreignId('suplidor_id')->nullable()->constrained('suplidor')->nullOnDelete();

            $table->string('unidad', 20)->default('unidad');    // unidad, libra, caja, docena...

            // Dinero en centavos enteros, nunca en coma flotante (ARQ-09).
            $table->bigInteger('costo_centavos')->default(0);
            $table->bigInteger('precio_centavos')->default(0);
            // Tasa en milésimas de punto: 11.5% es 11500.
            $table->integer('impuesto_milesimas')->default(0);

            // Caché de la suma de movimientos. La escribe solo el registrador.
            $table->decimal('existencia', 14, 3)->default(0);
            $table->decimal('existencia_minima', 14, 3)->default(0);

            $table->boolean('es_servicio')->default(false);     // PRO-03: sin inventario
            $table->boolean('activo')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz('borrado_en');

            $table->index(['empresa_id', 'activo']);
            $table->index(['empresa_id', 'categoria_id']);
            $table->index(['empresa_id', 'suplidor_id']);
            $table->index(['empresa_id', 'existencia']);
        });

        // SKU y código de barras únicos dentro de la empresa (PRO-01).
        DB::statement('create unique index producto_sku_unico on producto (empresa_id, lower(sku)) where sku is not null and borrado_en is null');
        DB::statement('create unique index producto_barras_unico on producto (empresa_id, codigo_barras) where codigo_barras is not null and borrado_en is null');
        DB::statement('create index producto_busqueda on producto (empresa_id, lower(nombre))');

        Schema::create('movimiento_inventario', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('producto')->cascadeOnDelete();

            // apertura | entrada | salida | venta | devolucion | ajuste | merma | traspaso
            $table->string('tipo', 20);
            // Con signo: lo que entra suma, lo que sale resta.
            $table->decimal('cantidad', 14, 3);
            $table->decimal('existencia_resultante', 14, 3);
            $table->bigInteger('costo_unitario_centavos')->nullable();

            $table->string('motivo', 60)->nullable();           // INV-03: obligatorio en ajustes
            $table->string('comentario', 300)->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('usuario')->nullOnDelete();

            // A qué documento responde el movimiento, cuando lo hay.
            $table->string('referencia_tipo', 30)->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['empresa_id', 'producto_id', 'id']);  // kardex por producto (INV-07)
        });

        foreach (['producto', 'movimiento_inventario'] as $tabla) {
            DB::statement("alter table {$tabla} enable row level security");
            DB::statement(<<<SQL
                create policy {$tabla}_app on {$tabla} for all to aiop_app
                using (empresa_id = app_empresa_id())
                with check (empresa_id = app_empresa_id());
            SQL);
            // Sin política para aiop_consola (ADM-08).
        }

        // Un movimiento no se edita ni se borra: se corrige con otro movimiento
        // (INV-01, PR-02). Lo impide la base, no solo el código.
        DB::statement('revoke update, delete on movimiento_inventario from aiop_app');
    }

    public function down(): void
    {
        DB::statement('drop policy if exists movimiento_inventario_app on movimiento_inventario');
        DB::statement('drop policy if exists producto_app on producto');
        Schema::dropIfExists('movimiento_inventario');
        Schema::dropIfExists('producto');
    }
};
