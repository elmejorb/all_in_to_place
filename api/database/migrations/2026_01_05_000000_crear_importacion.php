<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importaciones de catálogo (PRO-07, PRO-08).
 *
 * La importación son tres pasos y dos momentos distintos: primero se revisa lo
 * que traería el archivo, y solo si el usuario confirma se aplica. Entre un paso
 * y otro las filas ya interpretadas se guardan aquí, para no volver a subir el
 * archivo y para que quede constancia de quién importó qué (PRO-08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacion', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();

            $table->string('tipo', 20)->default('producto');
            $table->string('archivo_nombre', 200);
            $table->string('estado', 20)->default('previsualizada'); // previsualizada|aplicada|descartada
            $table->boolean('actualizar_existentes')->default(false);

            $table->integer('filas_total')->default(0);
            $table->integer('filas_nuevas')->default(0);
            $table->integer('filas_actualiza')->default(0);
            $table->integer('filas_error')->default(0);

            // Las filas ya interpretadas, con su diagnóstico por fila.
            $table->jsonb('filas')->nullable();

            $table->foreignId('usuario_id')->nullable()->constrained('usuario')->nullOnDelete();
            $table->timestampTz('aplicada_en')->nullable();
            $table->timestampsTz();

            $table->index(['empresa_id', 'created_at']);
        });

        DB::statement('alter table importacion enable row level security');

        DB::statement(<<<'SQL'
            create policy importacion_app on importacion for all to aiop_app
            using (empresa_id = app_empresa_id())
            with check (empresa_id = app_empresa_id());
        SQL);

        // Sin política para aiop_consola (ADM-08).
    }

    public function down(): void
    {
        DB::statement('drop policy if exists importacion_app on importacion');
        Schema::dropIfExists('importacion');
    }
};
