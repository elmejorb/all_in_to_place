<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empresa (la cuenta) y membresía (lo que da acceso a esa cuenta).
 *
 * El país y la moneda son de la empresa, no de la plataforma (ADM-22): los datos
 * actuales mezclan Puerto Rico y Colombia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();            // ARQ-13
            $table->string('nombre_legal', 150);
            $table->string('nombre_comercial', 150)->nullable();
            $table->string('registro_comerciante', 60)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('direccion_fisica')->nullable();
            $table->text('direccion_postal')->nullable();
            $table->char('pais', 2)->default('PR');         // ISO 3166-1
            $table->char('moneda', 3)->default('USD');      // ISO 4217
            $table->string('zona_horaria', 60)->default('America/Puerto_Rico'); // ARQ-10
            $table->char('idioma', 2)->default('es');
            $table->string('estado', 20)->default('prueba'); // prueba|activa|morosa|suspendida|cancelada
            $table->string('logo_ruta')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz('borrado_en');

            $table->index('estado');
        });

        Schema::create('membresia', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('empresa_id')->constrained('empresa')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuario')->cascadeOnDelete();
            $table->string('rol', 20);                      // propietario|administrador|gerente|empleado|contratista|contador
            $table->string('perfil', 20)->nullable();       // mostrador|almacen, solo para empleado (ROL-08)
            $table->boolean('activa')->default(true);
            $table->timestampsTz();

            $table->unique(['empresa_id', 'usuario_id']);   // ROL-09: un rol por empresa
            $table->index(['usuario_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membresia');
        Schema::dropIfExists('empresa');
    }
};
