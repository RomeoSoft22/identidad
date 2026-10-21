<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // claim "sub" del JWT: estable y no revela el id interno
            $table->string('nombres', 120);
            $table->string('apellidos', 120)->nullable();
            $table->string('correo')->unique();
            $table->timestamp('correo_verificado_en')->nullable();
            $table->string('contrasena'); // hash bcrypt
            $table->string('estado', 20)->default('activo')->index(); // activo | bloqueado | baja
            $table->unsignedSmallInteger('intentos_fallidos')->default(0);
            $table->timestamp('bloqueado_en')->nullable();
            $table->string('bloqueado_motivo', 60)->nullable();
            $table->boolean('debe_cambiar_contrasena')->default(false);
            $table->timestamp('contrasena_cambiada_en')->nullable();
            $table->timestamp('ultimo_ingreso_en')->nullable();
            $table->string('ultima_ip', 45)->nullable();
            $table->string('token_recordar', 100)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('creado_en')->nullable();
            $table->timestamp('actualizado_en')->nullable();
            $table->timestamp('eliminado_en')->nullable();
        });

        // Tablas del framework: los nombres de columna los fija Laravel.
        Schema::create('restablecimientos_contrasena', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sesiones_http', function (Blueprint $table) {
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
        Schema::dropIfExists('sesiones_http');
        Schema::dropIfExists('restablecimientos_contrasena');
        Schema::dropIfExists('usuarios');
    }
};
