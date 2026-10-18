<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada sistema cliente (ISTQB, PANEL, ...) está ligado a un cliente OAuth de Passport,
        // que guarda el secreto y las URLs de retorno permitidas.
        Schema::create('sistemas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre');
            $table->string('url_base')->nullable();
            $table->foreignUuid('oauth_client_id')->unique()->constrained('oauth_clients')->cascadeOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->string('codigo', 40);
            $table->string('nombre');
            $table->timestamps();
            $table->unique(['sistema_id', 'codigo']);
        });

        Schema::create('matriculas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->string('estado', 20)->default('activa'); // activa | suspendida
            $table->timestamp('vigente_hasta')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'sistema_id', 'rol_id']);
        });

        Schema::create('eventos_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sistema_id')->nullable()->constrained('sistemas')->nullOnDelete();
            $table->string('evento', 40)->index();
            $table->string('email')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('detalle')->nullable();
            $table->timestamp('creado_en')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_auditoria');
        Schema::dropIfExists('matriculas');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('sistemas');
    }
};
