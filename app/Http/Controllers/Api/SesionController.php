<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventoAuditoria;
use App\Models\Sistema;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Bloqueo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Sesión SSO de identidad (cookie HttpOnly del dominio de identidad), usada por identidad-web (Angular).
 * La contraseña solo llega aquí; los sistemas cliente nunca la ven.
 */
class SesionController extends Controller
{
    public const ERROR_GENERICO = 'El correo o la contraseña no son correctos. Revísalos e inténtalo de nuevo.';
    public const CUENTA_BLOQUEADA = 'Tu cuenta está bloqueada por seguridad. Pide al administrador que la desbloquee.';
    public const CUENTA_RECIEN_BLOQUEADA = 'Por seguridad, bloqueamos tu cuenta después de :n intentos fallidos. Pide al administrador que la desbloquee.';

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $user
            ? response()->json(['usuario' => $this->usuario($user)])
            : response()->json(['codigo' => 'sin_sesion', 'message' => 'No hay una sesión abierta.'], 401);
    }

    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ], [
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo válido, por ejemplo nombre@juarezcampos.net.',
            'password.required' => 'Escribe tu contraseña.',
        ]);
        $email = Str::lower(trim($datos['email']));

        // Límite por IP: frena pruebas masivas contra muchas cuentas y el bloqueo intencional de cuentas ajenas.
        $claveIp = 'login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($claveIp, config('identidad.login_por_minuto_ip'))) {
            Auditoria::registrar(EventoAuditoria::LIMITE_IP, email: $email);

            return $this->error('limite', 'Hubo demasiados intentos desde tu conexión. Espera '.RateLimiter::availableIn($claveIp).' segundos y vuelve a intentarlo.', 429);
        }
        RateLimiter::hit($claveIp, 60);

        $user = User::where('email', $email)->first();

        // Mismo trabajo de hash exista o no el correo, para no revelar qué cuentas existen por el tiempo de respuesta.
        $passwordCorrecta = Hash::check($datos['password'], $user?->password ?? '$2y$12$'.str_repeat('x', 53));

        if (! $user || $user->estado === User::BAJA) {
            Auditoria::registrar(EventoAuditoria::LOGIN_FALLIDO, $user, email: $email, detalle: ['motivo' => $user ? 'baja' : 'no_existe']);

            return $this->error('credenciales', self::ERROR_GENERICO);
        }

        if ($user->estaBloqueado()) {
            Auditoria::registrar(EventoAuditoria::INGRESO_CUENTA_BLOQUEADA, $user);

            // El aviso de bloqueo solo se muestra a quien conoce la contraseña.
            return $passwordCorrecta
                ? $this->error('bloqueada', self::CUENTA_BLOQUEADA, 423)
                : $this->error('credenciales', self::ERROR_GENERICO);
        }

        if (! $passwordCorrecta) {
            return Bloqueo::registrarFallo($user)
                ? $this->error('bloqueada', str_replace(':n', (string) config('identidad.max_intentos'), self::CUENTA_RECIEN_BLOQUEADA), 423)
                : $this->error('credenciales', self::ERROR_GENERICO);
        }

        $user->forceFill(['intentos_fallidos' => 0, 'ultimo_ingreso' => now()])->save();
        Auth::login($user);
        $request->session()->regenerate();
        Auditoria::registrar(EventoAuditoria::LOGIN_EXITOSO, $user);

        return response()->json(['usuario' => $this->usuario($user)]);
    }

    /**
     * Cierra la sesión SSO y revoca los tokens del usuario en todos los sistemas.
     * Devuelve a dónde volver solo si pertenece a la URL base de un sistema registrado.
     */
    public function logout(Request $request): JsonResponse
    {
        if ($user = $request->user()) {
            Bloqueo::revocarTokens($user);
            Auditoria::registrar(EventoAuditoria::LOGOUT, $user);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $destino = (string) $request->input('post_logout_redirect_uri', '');

        return response()->json(['redirect_to' => $destino !== '' && $this->destinoPermitido($destino) ? $destino : null]);
    }

    private function destinoPermitido(string $destino): bool
    {
        return Sistema::where('activo', true)->whereNotNull('url_base')->pluck('url_base')
            ->contains(fn (string $base) => $destino === rtrim($base, '/') || Str::startsWith($destino, rtrim($base, '/').'/'));
    }

    private function usuario(User $user): array
    {
        return ['nombre' => $user->name, 'email' => $user->email];
    }

    private function error(string $codigo, string $mensaje, int $estado = 422): JsonResponse
    {
        return response()->json(['codigo' => $codigo, 'message' => $mensaje], $estado);
    }
}
