<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventoAuditoria;
use App\Models\SesionSso;
use App\Models\Sistema;
use App\Models\Usuario;
use App\Support\Auditoria;
use App\Support\Bloqueo;
use App\Support\SesionesSso;
use App\Support\VerificacionCorreo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    // Solo lo ve quien escribió la contraseña correcta de una cuenta bloqueada.
    public const VERIFICACION_REQUERIDA = 'Por seguridad necesitamos confirmar que eres tú. Te enviamos un código de 6 dígitos a :correo.';

    public const CODIGO_INCORRECTO = 'El código no es correcto. Revísalo e inténtalo de nuevo.';

    public const CODIGO_VENCIDO = 'El código venció o ya no es válido. Vuelve a ingresar tu contraseña para recibir uno nuevo.';

    public function show(Request $request): JsonResponse
    {
        $user = SesionesSso::usuarioVigente($request);

        return $user
            ? response()->json(['usuario' => $this->usuario($user)])
            : response()->json(['codigo' => 'sin_sesion', 'message' => 'No hay una sesión abierta.'], 401);
    }

    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            // Los envía el navegador (identidad-web) para la auditoría de la sesión.
            'zona_horaria' => ['nullable', 'string', 'max:60', 'timezone:all'],
            'idioma' => ['nullable', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ], [
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo válido, por ejemplo nombre@juarezcampos.net.',
            'password.required' => 'Escribe tu contraseña.',
        ]);
        $email = Str::lower(trim($datos['email']));

        // Límite por IP: frena pruebas masivas contra muchas cuentas y el bloqueo intencional de cuentas ajenas.
        $claveIp = 'login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($claveIp, config('identidad.login_por_minuto_ip'))) {
            Auditoria::registrar(EventoAuditoria::LIMITE_IP, correo: $email);

            return $this->error('limite', 'Hubo demasiados intentos desde tu conexión. Espera '.RateLimiter::availableIn($claveIp).' segundos y vuelve a intentarlo.', 429);
        }
        RateLimiter::hit($claveIp, 60);

        $user = Usuario::where('correo', $email)->first();

        // Mismo trabajo de hash exista o no el correo, para no revelar qué cuentas existen por el tiempo de respuesta.
        $passwordCorrecta = Hash::check($datos['password'], $user?->contrasena ?? '$2y$12$'.str_repeat('x', 53));

        if (! $user || in_array($user->estado, [Usuario::BAJA, Usuario::PENDIENTE], true)) {
            Auditoria::registrar(EventoAuditoria::LOGIN_FALLIDO, $user, correo: $email, detalle: ['motivo' => $user?->estado ?? 'no_existe']);

            return $this->error('credenciales', self::ERROR_GENERICO);
        }

        $contexto = [
            'zona_horaria' => $datos['zona_horaria'] ?? null,
            'idioma' => $datos['idioma'] ?? (substr((string) $request->getPreferredLanguage(), 0, 35) ?: null),
        ];

        if ($user->estaBloqueado()) {
            Auditoria::registrar(EventoAuditoria::INGRESO_CUENTA_BLOQUEADA, $user, detalle: ['contrasena_correcta' => $passwordCorrecta]);

            // Quien no conoce la contraseña nunca se entera de que la cuenta está bloqueada.
            if (! $passwordCorrecta) {
                return $this->error('credenciales', self::ERROR_GENERICO);
            }

            // Contraseña correcta: segundo factor por correo para desbloquear.
            if (! VerificacionCorreo::enviar($user, $request, $contexto)) {
                return $this->error('limite', 'Ya enviamos varios códigos a tu correo. Revisa tu bandeja (y la de spam) o espera una hora.', 429);
            }

            return response()->json([
                'codigo' => 'verificacion_requerida',
                'message' => str_replace(':correo', VerificacionCorreo::enmascarar($user->correo), self::VERIFICACION_REQUERIDA),
                'correo' => VerificacionCorreo::enmascarar($user->correo),
            ], 423);
        }

        if (! $passwordCorrecta) {
            // Mismo mensaje aunque este fallo bloquee la cuenta: no se revela la política de bloqueo.
            Bloqueo::registrarFallo($user);

            return $this->error('credenciales', self::ERROR_GENERICO);
        }

        return $this->completarIngreso($user, $request, $contexto);
    }

    /** Segundo paso de una cuenta bloqueada: el código de 6 dígitos que llegó al correo. */
    public function verificarCodigo(Request $request): JsonResponse
    {
        $datos = $request->validate(['codigo' => ['required', 'string', 'regex:/^\d{6}$/']], [
            'codigo.required' => 'Escribe el código de 6 dígitos.',
            'codigo.regex' => 'El código tiene 6 dígitos.',
        ]);

        $claveIp = 'login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($claveIp, config('identidad.login_por_minuto_ip'))) {
            return $this->error('limite', 'Hubo demasiados intentos desde tu conexión. Espera '.RateLimiter::availableIn($claveIp).' segundos y vuelve a intentarlo.', 429);
        }
        RateLimiter::hit($claveIp, 60);

        [$resultado, $user, $contexto] = VerificacionCorreo::verificar($request, $datos['codigo']);

        return match ($resultado) {
            'ok' => $this->desbloquearEIngresar($user, $request, $contexto),
            'incorrecto' => $this->error('codigo_incorrecto', self::CODIGO_INCORRECTO),
            default => $this->error('codigo_vencido', self::CODIGO_VENCIDO, 410),
        };
    }

    private function desbloquearEIngresar(Usuario $user, Request $request, array $contexto): JsonResponse
    {
        if ($user->estado !== Usuario::ACTIVO && ! $user->estaBloqueado()) {
            return $this->error('credenciales', self::ERROR_GENERICO);
        }
        if ($user->estaBloqueado()) {
            Bloqueo::desbloquear($user, via: 'codigo_correo');
        }

        return $this->completarIngreso($user, $request, $contexto);
    }

    private function completarIngreso(Usuario $user, Request $request, array $contexto): JsonResponse
    {
        SesionesSso::ingresar($user, $request, $contexto['zona_horaria'] ?? null, $contexto['idioma'] ?? null);

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
            if ($sesion = SesionesSso::actual()) {
                SesionesSso::cerrar($sesion, SesionSso::CIERRE_LOGOUT);
            }
            SesionesSso::salirDelNavegador($request);
        }

        $destino = (string) $request->input('post_logout_redirect_uri', '');

        return response()->json(['redirect_to' => $destino !== '' && $this->destinoPermitido($destino) ? $destino : null]);
    }

    private function destinoPermitido(string $destino): bool
    {
        return Sistema::where('activo', true)->whereNotNull('url_base')->pluck('url_base')
            ->contains(fn (string $base) => $destino === rtrim($base, '/') || Str::startsWith($destino, rtrim($base, '/').'/'));
    }

    private function usuario(Usuario $user): array
    {
        return ['nombre' => $user->nombreCompleto(), 'email' => $user->correo];
    }

    private function error(string $codigo, string $mensaje, int $estado = 422): JsonResponse
    {
        return response()->json(['codigo' => $codigo, 'message' => $mensaje], $estado);
    }
}
