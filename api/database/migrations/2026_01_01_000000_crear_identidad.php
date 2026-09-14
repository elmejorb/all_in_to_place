<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad de las dos aplicaciones.
 *
 * `usuario` es la identidad global de las personas que trabajan en una empresa
 * (ARQ-05: la identidad no lleva empresa; el acceso lo da la membresía).
 * `usuario_plataforma` es el ámbito de la consola y no se cruza nunca con el
 * anterior (ARQ-03): son tablas distintas, con sesiones y guards distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();          // identificador público (ARQ-13)
            $table->string('nombres', 80);
            $table->string('apellidos', 80);
            $table->string('email', 190)->unique();
            $table->timestampTz('email_verificado_en')->nullable();
            $table->string('password');
            $table->boolean('activo')->default(true);
            $table->timestampTz('ultimo_acceso_en')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz('borrado_en');          // ARQ-12
        });

        Schema::create('usuario_plataforma', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nombres', 80);
            $table->string('apellidos', 80);
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->string('rol', 20);                    // superadmin | soporte | comercial (ROL-04)
            $table->boolean('activo')->default(true);
            $table->text('totp_secreto')->nullable();     // cifrado en la aplicación (SEG-19)
            $table->timestampTz('ultimo_acceso_en')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz('borrado_en');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('usuario_plataforma');
        Schema::dropIfExists('usuario');
    }
};
