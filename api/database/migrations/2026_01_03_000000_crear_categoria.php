<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías del catálogo (CAT-01).
 *
 * El color es una etiqueta visual elegida de una paleta cerrada, no un campo
 * libre: así ninguna categoría queda ilegible sobre el fondo de su tema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categoria', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();

            $table->string('nombre', 80);
            $table->string('descripcion', 300)->nullable();
            $table->string('color', 20)->default('pizarra');
            $table->integer('orden')->default(0);        // CAT-04, se usará al reordenar

            $table->timestampsTz();
            $table->softDeletesTz('borrado_en');

            $table->index(['empresa_id', 'orden']);
        });

        // CAT-01: el nombre es único dentro de la empresa, sin distinguir mayúsculas.
        DB::statement('create unique index categoria_nombre_unico on categoria (empresa_id, lower(nombre)) where borrado_en is null');

        DB::statement('alter table categoria enable row level security');

        DB::statement(<<<'SQL'
            create policy categoria_app on categoria for all to aiop_app
            using (empresa_id = app_empresa_id())
            with check (empresa_id = app_empresa_id());
        SQL);

        // Sin política para aiop_consola (ADM-08).
    }

    public function down(): void
    {
        DB::statement('drop policy if exists categoria_app on categoria');
        Schema::dropIfExists('categoria');
    }
};
