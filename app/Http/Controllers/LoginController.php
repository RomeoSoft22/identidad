<?php

namespace App\Http\Controllers;

use App\Models\EventoAuditoria;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Bloqueo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    private const ERROR_GENERICO = 'Correo o contraseña incorrectos.';

    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::check() && $request->session()->has('url.intended')) {
            return redirect()->intended();
        }

        return view('login', ['sesionActiva' => Auth::user()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $email = Str::lower(trim($datos['email']));

        // Límite por IP: frena pruebas masivas contra muchas cuentas y el bloqueo intencional de cuentas ajenas.
        $claveIp = 'login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($claveIp, config('identidad.login_por_minuto_ip'))) {
            Auditoria::registrar(EventoAuditoria::LIMITE_IP, email: $email);

            return back()->withInput(['email' => $email])->withErrors([
                'email' => 'Demasiados intentos desde tu red. Espera '.RateLimiter::availableIn($claveIp).' segundos.',
            ]);
        }
        RateLimiter::hit($claveIp, 60);

        $user = User::where('email', $email)->first();

        // Mismo trabajo de hash exista o no el correo, para no revelar qué cuentas existen por el tiempo de respuesta.
        $passwordCorrecta = Hash::check($datos['password'], $user?->password ?? '$2y$12$'.str_repeat('x', 53));

        if (! $user || $user->estado === User::BAJA) {
            Auditoria::registrar(EventoAuditoria::LOGIN_FALLIDO, $user, email: $email, detalle: ['motivo' => $user ? 'baja' : 'no_existe']);

            return $this->fallo($email);
        }

        if ($user->estaBloqueado()) {
            Auditoria::registrar(EventoAuditoria::INGRESO_CUENTA_BLOQUEADA, $user);

            // El mensaje de bloqueo solo se muestra a quien conoce la contraseña.
            return $passwordCorrecta
                ? $this->fallo($email, 'Tu cuenta está bloqueada por intentos fallidos. Contacta al administrador.')
                : $this->fallo($email);
        }

        if (! $passwordCorrecta) {
            $bloqueada = Bloqueo::registrarFallo($user);

            return $bloqueada
                ? $this->fallo($email, 'Tu cuenta fue bloqueada por intentos fallidos. Contacta al administrador.')
                : $this->fallo($email);
        }

        $user->forceFill(['intentos_fallidos' => 0, 'ultimo_ingreso' => now()])->save();
        Auth::login($user);
        $request->session()->regenerate();
        Auditoria::registrar(EventoAuditoria::LOGIN_EXITOSO, $user);

        return redirect()->intended(route('login'));
    }

    private function fallo(string $email, string $mensaje = self::ERROR_GENERICO): RedirectResponse
    {
        return back()->withInput(['email' => $email])->withErrors(['email' => $mensaje]);
    }
}
