<?php

use App\Models\Acceso;
use App\Models\CodigoVerificacion;
use App\Models\EventoAuditoria;
use App\Models\Matricula;
use App\Models\Rol;
use App\Models\SesionSso;
use App\Models\Sistema;
use App\Models\Usuario;
use App\Support\Bloqueo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

// Administración desde consola mientras no exista panel.juarezcampos.net.

Artisan::command('identidad:usuario {correo} {nombres} {apellidos?} {--password= : Si se omite, se pide de forma oculta}', function (string $correo, string $nombres, ?string $apellidos = null) {
    $correo = Str::lower(trim($correo));
    if (Usuario::withTrashed()->where('correo', $correo)->exists()) {
        return $this->error("Ya existe un usuario con el correo {$correo}.");
    }

    $password = $this->option('password') ?? $this->secret('Contraseña (mínimo 10 caracteres)');
    if (strlen((string) $password) < 10) {
        return $this->error('La contraseña debe tener al menos 10 caracteres.');
    }

    $usuario = Usuario::create(['nombres' => $nombres, 'apellidos' => $apellidos, 'correo' => $correo, 'contrasena' => $password]);
    $this->info("Usuario creado: {$usuario->correo} (uuid {$usuario->uuid}).");
})->purpose('Crea un usuario');

Artisan::command('identidad:sistema {codigo} {nombre} {--url= : URL base del sistema} {--publico : Cliente público (SPA con PKCE, sin secreto)} {--redirect=* : URL de retorno permitida (se puede repetir)}', function (string $codigo, string $nombre, ClientRepository $clientes) {
    $codigo = Str::upper(trim($codigo));
    if (Sistema::where('codigo', $codigo)->exists()) {
        return $this->error("El sistema {$codigo} ya existe.");
    }
    $redirects = $this->option('redirect');
    if ($redirects === []) {
        return $this->error('Indica al menos una URL de retorno con --redirect=https://.../callback');
    }

    [$sistema, $cliente] = DB::transaction(function () use ($codigo, $nombre, $redirects, $clientes) {
        $cliente = $clientes->createAuthorizationCodeGrantClient($nombre, $redirects, confidential: ! $this->option('publico'));
        $sistema = Sistema::create([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'url_base' => $this->option('url'),
            'oauth_client_id' => $cliente->id,
        ]);

        return [$sistema, $cliente];
    });

    $this->info("Sistema {$sistema->codigo} registrado.");
    $this->table(['Dato', 'Valor'], [
        ['client_id', $cliente->id],
        ['client_secret', $cliente->plainSecret],
        ['redirect_uris', implode(', ', $redirects)],
    ]);
    $this->warn('Guarda el client_secret ahora: no se puede volver a ver.');
})->purpose('Registra un sistema cliente y genera su client_id y secreto');

Artisan::command('identidad:rol {sistema} {codigo} {nombre}', function (string $sistema, string $codigo, string $nombre) {
    $sis = Sistema::where('codigo', Str::upper($sistema))->first();
    if (! $sis) {
        return $this->error("No existe el sistema {$sistema}.");
    }
    $rol = Rol::firstOrCreate(['sistema_id' => $sis->id, 'codigo' => Str::upper($codigo)], ['nombre' => $nombre]);
    $this->info("Rol {$rol->codigo} disponible en {$sis->codigo}.");
})->purpose('Crea un rol dentro de un sistema');

Artisan::command('identidad:matricular {correo} {sistema} {rol}', function (string $correo, string $sistema, string $rol) {
    $usuario = Usuario::where('correo', Str::lower($correo))->first();
    $sis = Sistema::where('codigo', Str::upper($sistema))->first();
    $r = $sis?->roles()->where('codigo', Str::upper($rol))->first();
    if (! $usuario || ! $sis || ! $r) {
        return $this->error('Revisa el correo, el código de sistema y el código de rol.');
    }
    Matricula::updateOrCreate(
        ['usuario_id' => $usuario->id, 'sistema_id' => $sis->id, 'rol_id' => $r->id],
        ['estado' => Matricula::ACTIVA, 'vigente_desde' => now(), 'vigente_hasta' => null],
    );
    $this->info("{$usuario->correo} matriculado en {$sis->codigo} con rol {$r->codigo}.");
})->purpose('Matricula un usuario en un sistema con un rol');

Artisan::command('identidad:desbloquear {correo}', function (string $correo) {
    $usuario = Usuario::where('correo', Str::lower($correo))->first();
    if (! $usuario) {
        return $this->error("No existe el usuario {$correo}.");
    }
    Bloqueo::desbloquear($usuario);
    $this->info("Cuenta {$usuario->correo} desbloqueada.");
})->purpose('Desbloquea una cuenta bloqueada por intentos fallidos');

Artisan::command('identidad:depurar-auditoria', function () {
    $limite = now()->subMonths((int) config('identidad.retencion_auditoria_meses'));

    $eventos = EventoAuditoria::where('creado_en', '<', $limite)->delete();
    $accesos = Acceso::where('creado_en', '<', $limite)->delete();
    $sesiones = SesionSso::whereNotNull('cerrada_en')->where('cerrada_en', '<', $limite)->delete();
    CodigoVerificacion::where('expira_en', '<', now()->subDay())->delete(); // los códigos no sirven después de vencer

    $this->info("Depurado hasta {$limite->toDateString()}: {$eventos} eventos, {$accesos} accesos, {$sesiones} sesiones.");
})->purpose('Borra la auditoría más antigua que el periodo de retención (12 meses por defecto)');

Schedule::command('passport:purge')->daily();
Schedule::command('identidad:depurar-auditoria')->dailyAt('03:00');
