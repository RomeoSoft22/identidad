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
            $table->string('descripcion')->nullable();
            $table->string('url_base')->nullable();
            $table->foreignUuid('oauth_client_id')->unique()->constrained('oauth_clients')->cascadeOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamp('creado_en')->nullable();
            $table->timestamp('actualizado_en')->nullable();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->string('codigo', 40);
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->timestamp('creado_en')->nullable();
            $table->timestamp('actualizado_en')->nullable();
            $table->unique(['sistema_id', 'codigo']);
        });

        Schema::create('matriculas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->string('estado', 20)->default('activa'); // activa | suspendida | retirada
            $table->timestamp('vigente_desde')->nullable();
            $table->timestamp('vigente_hasta')->nullable();
            $table->timestamp('ultimo_acceso_en')->nullable(); // última visita a este sistema
            $table->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('creado_en')->nullable();
            $table->timestamp('actualizado_en')->nullable();
            $table->unique(['usuario_id', 'sistema_id', 'rol_id']);
        });

        // Equipo + navegador desde el que entra un usuario. La huella identifica la combinación
        // usuario + user-agent, para reconocer un dispositivo ya usado o detectar uno nuevo.
        Schema::create('dispositivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->char('huella', 64);
            $table->string('navegador', 60)->nullable();
            $table->string('navegador_version', 30)->nullable();
            $table->string('sistema_operativo', 60)->nullable();
            $table->string('so_version', 30)->nullable();
            $table->string('tipo', 20)->nullable(); // escritorio | movil | tablet | otro
            $table->text('user_agent')->nullable();
            $table->boolean('confiable')->default(false);
            $table->timestamp('primer_uso_en')->nullable();
            $table->timestamp('ultimo_uso_en')->nullable();
            $table->unique(['usuario_id', 'huella']);
        });

        // Un ingreso con contraseña en identidad. Los demás sistemas reutilizan esta sesión (SSO).
        Schema::create('sesiones_sso', function (Blueprint $table) {
            $table->id();
            $table->string('sid', 64)->unique();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('zona_horaria', 60)->nullable(); // la informa el navegador
            $table->string('idioma', 35)->nullable();
            $table->timestamp('iniciada_en')->nullable();
            $table->timestamp('ultima_actividad_en')->nullable();
            $table->timestamp('expira_en')->nullable();
            $table->timestamp('cerrada_en')->nullable()->index();
            $table->string('motivo_cierre', 20)->nullable(); // logout | expirada | revocada | bloqueo
            $table->index(['usuario_id', 'iniciada_en']);
        });

        // Cada entrada del navegador a un sistema dentro de una sesión: el primer ingreso (login)
        // o uno posterior sin contraseña (sso).
        Schema::create('accesos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_id')->constrained('sesiones_sso')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->string('tipo', 20); // login | sso
            $table->string('ip', 45)->nullable();
            $table->timestamp('creado_en')->useCurrent()->index();
            $table->index(['usuario_id', 'creado_en']);
        });

        Schema::create('eventos_auditoria', function (Blueprint $table) {
            $table->id();
            $table->string('evento', 40)->index();
            $table->string('resultado', 20)->index(); // exito | fallo | denegado | info
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('sistema_id')->nullable()->constrained('sistemas')->nullOnDelete();
            $table->foreignId('sesion_id')->nullable()->constrained('sesiones_sso')->nullOnDelete();
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('usuarios')->nullOnDelete(); // administrador que actuó
            $table->string('correo_intentado')->nullable();
            $table->string('ip', 45)->nullable()->index();
            $table->string('ip_reenviada')->nullable(); // cabecera X-Forwarded-For tal cual llegó
            $table->string('navegador', 60)->nullable();
            $table->string('sistema_operativo', 60)->nullable();
            $table->string('tipo_dispositivo', 20)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('metodo_ruta', 120)->nullable();
            $table->json('detalle')->nullable();
            $table->timestamp('creado_en')->useCurrent()->index();
            $table->index(['usuario_id', 'creado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_auditoria');
        Schema::dropIfExists('accesos');
        Schema::dropIfExists('sesiones_sso');
        Schema::dropIfExists('dispositivos');
        Schema::dropIfExists('matriculas');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('sistemas');
    }
};
