<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La hoja de cuadre: el cierre de caja que la panadería ya lleva a mano.
 *
 * Se guarda **lo que se escribe**, no lo que se calcula. El total de efectivo y
 * el depósito salen siempre de `App\Domain\Cuadre` a partir de estas cinco
 * cifras y de los gastos, de modo que no puede haber una hoja cuyos totales
 * no correspondan a sus propios números.
 *
 * Una hoja por fecha y turno: si alguien intenta cuadrar dos veces el mismo
 * turno, lo impide la base y no el código (CAJ-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hoja_cuadre', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();

            $table->date('fecha');
            $table->string('turno', 2);            // am | pm

            $table->bigInteger('efectivo_inicial_centavos')->default(0);
            $table->bigInteger('ventas_lectura_centavos')->default(0);
            $table->bigInteger('efectivo_cambio_centavos')->default(0);
            $table->bigInteger('tarjeta_centavos')->default(0);
            $table->bigInteger('ath_movil_centavos')->default(0);

            $table->text('notas')->nullable();

            // Quién la cuadró: el empleado solo ve las suyas (matriz del doc 04).
            $table->foreignId('usuario_id')->nullable()->constrained('usuario')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['empresa_id', 'fecha', 'turno']);
            $table->index(['empresa_id', 'fecha']);
        });

        Schema::create('gasto_cuadre', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();
            $table->foreignId('hoja_id')->constrained('hoja_cuadre')->cascadeOnDelete();

            $table->integer('orden')->default(0);
            $table->string('descripcion', 200);
            $table->bigInteger('monto_centavos')->default(0);

            $table->index(['hoja_id', 'orden']);
        });

        foreach (['hoja_cuadre', 'gasto_cuadre'] as $tabla) {
            DB::statement("alter table {$tabla} enable row level security");
            DB::statement(<<<SQL
                create policy {$tabla}_app on {$tabla} for all to aiop_app
                using (empresa_id = app_empresa_id())
                with check (empresa_id = app_empresa_id());
            SQL);
            // Sin política para aiop_consola (ADM-08).
        }
    }

    public function down(): void
    {
        foreach (['gasto_cuadre', 'hoja_cuadre'] as $tabla) {
            DB::statement("drop policy if exists {$tabla}_app on {$tabla}");
            Schema::dropIfExists($tabla);
        }
    }
};
