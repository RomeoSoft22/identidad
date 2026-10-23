<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ingreso con Google: la cuenta queda vinculada por el identificador estable de Google ("sub"),
     * no solo por el correo. Quien entra por primera vez con Google queda "pendiente" hasta que un
     * administrador lo apruebe en el panel.
     */
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('google_sub', 64)->nullable()->unique()->after('correo');
            $table->string('origen', 20)->default('panel')->after('google_sub'); // panel | consola | google
            $table->foreignId('aprobado_por')->nullable()->after('creado_por')->constrained('usuarios')->nullOnDelete();
            $table->timestamp('aprobado_en')->nullable()->after('aprobado_por');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aprobado_por');
            $table->dropUnique(['google_sub']);
            $table->dropColumn(['google_sub', 'origen', 'aprobado_en']);
        });
    }
};
