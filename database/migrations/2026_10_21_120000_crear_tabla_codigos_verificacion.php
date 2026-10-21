<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Códigos de 6 dígitos enviados al correo (hoy: desbloquear una cuenta bloqueada tras
     * ingresar la contraseña correcta). Solo se guarda el hash del código.
     */
    public function up(): void
    {
        Schema::create('codigos_verificacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('proposito', 30); // desbloqueo
            $table->string('codigo_hash');
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamp('expira_en');
            $table->timestamp('usado_en')->nullable();
            $table->timestamp('creado_en')->useCurrent();
            $table->index(['usuario_id', 'proposito', 'creado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codigos_verificacion');
    }
};
