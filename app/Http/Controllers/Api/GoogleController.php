<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SolicitudAcceso;
use App\Models\EventoAuditoria;
use App\Models\Sistema;
use App\Models\Usuario;
use App\Support\Auditoria;
use App\Support\SesionesSso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as UsuarioGoogle;
use Throwable;

/**
 * Ingreso con Google (OpenID Connect). Google solo confirma QUIÉN es la persona; el acceso lo sigue
 * decidiendo identidad:
 *  - cuenta activa (creada en el panel o ya aprobada)  → entra y luego, como siempre, solo a los
 *    sistemas donde tenga matrícula;
 *  - sin cuenta → se registra como "pendiente" y se avisa a los administradores del panel;
 *  - pendiente, de baja o bloqueada → no entra.
 *
 * Se llama con navegación completa (no XHR) desde identidad-web: /api/google/redirect → Google →
 * /api/google/callback → vuelve a la pantalla de identidad-web de la que salió.
 */
class GoogleController extends Controller
{
    private const CLAVE_SESION = 'google_ingreso';

    /** Métodos de ingreso disponibles, para que identidad-web muestre u oculte el botón. */
    public function metodos(): JsonResponse
    {
        return response()->json(['google' => self::habilitado()]);
    }

    public function redirigir(Request $request): RedirectResponse
    {
        if (! self::habilitado()) {
            return $this->irA('/login?google=no_disponible');
        }

        $datos = $request->validate([
            'retorno' => ['nullable', 'string', 'max:2000'],
            'zona_horaria' => ['nullable', 'string', 'max:60', 'timezone:all'],
            'idioma' => ['nullable', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ]);
        $request->session()->put(self::CLAVE_SESION, [
            'retorno' => self::retornoSeguro($datos['retorno'] ?? null),
            'zona_horaria' => $datos['zona_horaria'] ?? null,
            'idioma' => $datos['idioma'] ?? (substr((string) $request->getPreferredLanguage(), 0, 35) ?: null),
        ]);

        // Socialite guarda un "state" en la sesión (anti-CSRF) y lo valida al volver.
        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $contexto = $request->session()->pull(self::CLAVE_SESION, []);
        $retorno = $contexto['retorno'] ?? '/login';

        if ($request->filled('error')) {
            return $this->volver($retorno, 'cancelado');
        }

        try {
            /** @var UsuarioGoogle $g */
            $g = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::notice('Google rechazó o no completó el ingreso: '.$e->getMessage(), ['ip' => $request->ip()]);

            return $this->volver($retorno, 'error');
        }

        $correo = Str::lower(trim((string) $g->getEmail()));
        $sub = (string) $g->getId();
        if ($correo === '' || $sub === '' || ! ($g->user['email_verified'] ?? $g->user['verified_email'] ?? false)) {
            Auditoria::registrar(EventoAuditoria::LOGIN_FALLIDO, correo: $correo ?: null, detalle: ['metodo' => 'google', 'motivo' => 'correo_no_verificado']);

            return $this->volver($retorno, 'correo_no_verificado');
        }

        $usuario = Usuario::withTrashed()->where('google_sub', $sub)->first()
            ?? Usuario::withTrashed()->where('correo', $correo)->first();

        if (! $usuario) {
            $usuario = $this->registrarSolicitud($g, $correo, $sub);

            return $this->volver($retorno, 'pendiente');
        }

        if ($usuario->google_sub !== null && $usuario->google_sub !== $sub) {
            // El correo coincide pero es otra cuenta de Google: no se mezclan identidades.
            Auditoria::registrar(EventoAuditoria::LOGIN_FALLIDO, $usuario, detalle: ['metodo' => 'google', 'motivo' => 'otra_cuenta_google']);

            return $this->volver($retorno, 'error');
        }

        if ($usuario->trashed() || $usuario->estado === Usuario::BAJA) {
            Auditoria::registrar(EventoAuditoria::LOGIN_FALLIDO, $usuario, detalle: ['metodo' => 'google', 'motivo' => 'baja']);

            return $this->volver($retorno, 'sin_acceso');
        }

        if ($usuario->estado === Usuario::PENDIENTE) {
            Auditoria::registrar(EventoAuditoria::LOGIN_FALLIDO, $usuario, detalle: ['metodo' => 'google', 'motivo' => 'pendiente']);

            return $this->volver($retorno, 'pendiente');
        }

        if ($usuario->estaBloqueado()) {
            Auditoria::registrar(EventoAuditoria::INGRESO_CUENTA_BLOQUEADA, $usuario, detalle: ['metodo' => 'google']);

            return $this->volver($retorno, 'bloqueada');
        }

        if ($usuario->google_sub === null) {
            $usuario->forceFill(['google_sub' => $sub])->save(); // primera vez con Google: se vincula
        }

        SesionesSso::ingresar($usuario, $request, $contexto['zona_horaria'] ?? null, $contexto['idioma'] ?? null, 'google');

        return $this->irA($retorno);
    }

    public static function habilitado(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    private function registrarSolicitud(UsuarioGoogle $g, string $correo, string $sub): Usuario
    {
        $nombres = trim((string) ($g->user['given_name'] ?? '')) ?: (trim((string) $g->getName()) ?: Str::before($correo, '@'));

        // Se guarda de una vez como "pendiente": nunca existe, ni un instante, como cuenta activa.
        $usuario = (new Usuario([
            'nombres' => Str::limit($nombres, 120, ''),
            'apellidos' => Str::limit(trim((string) ($g->user['family_name'] ?? '')), 120, '') ?: null,
            'correo' => $correo,
            // Sin contraseña utilizable: entra con Google (o el administrador le define una).
            'contrasena' => Str::password(48),
        ]))->forceFill([
            'google_sub' => $sub,
            'origen' => Usuario::ORIGEN_GOOGLE,
            'estado' => Usuario::PENDIENTE,
            'correo_verificado_en' => now(),
        ]);
        $usuario->save();

        Auditoria::registrar(EventoAuditoria::SOLICITUD_ACCESO, $usuario, detalle: ['metodo' => 'google']);
        $this->avisarAdministradores($usuario);

        return $usuario;
    }

    /** Correo a cada administrador con matrícula vigente en el panel. Un fallo del correo no frena la solicitud. */
    private function avisarAdministradores(Usuario $usuario): void
    {
        $panel = Sistema::where('codigo', config('identidad.sistema_panel'))->first();
        if (! $panel) {
            return;
        }

        $correos = Usuario::query()
            ->where('estado', Usuario::ACTIVO)
            ->whereHas('matriculas', fn ($q) => $q->vigentes()->where('matriculas.sistema_id', $panel->id)
                ->whereHas('rol', fn ($r) => $r->where('codigo', 'ADMIN')))
            ->pluck('correo');

        foreach ($correos as $correo) {
            try {
                Mail::to($correo)->send(new SolicitudAcceso($usuario->nombreCompleto(), $usuario->correo, $usuario->id));
            } catch (Throwable $e) {
                Log::warning('No se pudo avisar de una solicitud de acceso: '.$e->getMessage());
            }
        }
    }

    /** Solo se vuelve a pantallas propias de identidad-web (nunca a otro dominio). */
    private static function retornoSeguro(?string $retorno): string
    {
        return $retorno !== null && preg_match('#^/(authorize|login)(\?[^\s]*)?$#', $retorno) === 1 ? $retorno : '/login';
    }

    private function volver(string $retorno, string $codigo): RedirectResponse
    {
        return $this->irA($retorno.(str_contains($retorno, '?') ? '&' : '?').'google='.$codigo);
    }

    /**
     * Redirección relativa ("/login?..."): el navegador la resuelve contra el dominio de identidad-web.
     * Con una absoluta, detrás de un proxy (desarrollo) se mandaría al puerto interno de la API.
     */
    private function irA(string $ruta): RedirectResponse
    {
        return redirect()->away($ruta);
    }
}
