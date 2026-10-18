<?php

use App\Models\Matricula;
use App\Models\Rol;
use App\Models\Sistema;
use App\Models\User;
use App\Support\Bloqueo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

// Administración desde consola mientras no exista panel.juarezcampos.net.

Artisan::command('identidad:usuario {email} {nombre} {--password= : Si se omite, se pide de forma oculta}', function (string $email, string $nombre) {
    $email = Str::lower(trim($email));
    if (User::where('email', $email)->exists()) {
        return $this->error("Ya existe un usuario con el correo {$email}.");
    }

    $password = $this->option('password') ?? $this->secret('Contraseña (mínimo 10 caracteres)');
    if (strlen((string) $password) < 10) {
        return $this->error('La contraseña debe tener al menos 10 caracteres.');
    }

    $user = User::create(['name' => $nombre, 'email' => $email, 'password' => $password]);
    $this->info("Usuario creado: {$user->email} (uuid {$user->uuid}).");
})->purpose('Crea un usuario');

Artisan::command('identidad:sistema {codigo} {nombre} {--url= : URL base del sistema} {--redirect=* : URL de retorno permitida (se puede repetir)}', function (string $codigo, string $nombre, ClientRepository $clientes) {
    $codigo = Str::upper(trim($codigo));
    if (Sistema::where('codigo', $codigo)->exists()) {
        return $this->error("El sistema {$codigo} ya existe.");
    }
    $redirects = $this->option('redirect');
    if ($redirects === []) {
        return $this->error('Indica al menos una URL de retorno con --redirect=https://.../callback');
    }

    [$sistema, $cliente] = DB::transaction(function () use ($codigo, $nombre, $redirects, $clientes) {
        $cliente = $clientes->createAuthorizationCodeGrantClient($nombre, $redirects, confidential: true);
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

Artisan::command('identidad:matricular {email} {sistema} {rol}', function (string $email, string $sistema, string $rol) {
    $user = User::where('email', Str::lower($email))->first();
    $sis = Sistema::where('codigo', Str::upper($sistema))->first();
    $r = $sis?->roles()->where('codigo', Str::upper($rol))->first();
    if (! $user || ! $sis || ! $r) {
        return $this->error('Revisa el correo, el código de sistema y el código de rol.');
    }
    Matricula::updateOrCreate(
        ['user_id' => $user->id, 'sistema_id' => $sis->id, 'rol_id' => $r->id],
        ['estado' => Matricula::ACTIVA, 'vigente_hasta' => null],
    );
    $this->info("{$user->email} matriculado en {$sis->codigo} con rol {$r->codigo}.");
})->purpose('Matricula un usuario en un sistema con un rol');

Artisan::command('identidad:desbloquear {email}', function (string $email) {
    $user = User::where('email', Str::lower($email))->first();
    if (! $user) {
        return $this->error("No existe el usuario {$email}.");
    }
    Bloqueo::desbloquear($user);
    $this->info("Cuenta {$user->email} desbloqueada.");
})->purpose('Desbloquea una cuenta bloqueada por intentos fallidos');

Schedule::command('passport:purge')->daily();
