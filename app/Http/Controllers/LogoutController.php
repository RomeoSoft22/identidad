<?php

namespace App\Http\Controllers;

use App\Models\EventoAuditoria;
use App\Models\Sistema;
use App\Support\Auditoria;
use App\Support\Bloqueo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LogoutController extends Controller
{
    /**
     * Cierra la sesión SSO y revoca los tokens del usuario en todos los sistemas.
     * Solo redirige a post_logout_redirect_uri si pertenece a la URL base de un sistema registrado.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($user = $request->user()) {
            Bloqueo::revocarTokens($user);
            Auditoria::registrar(EventoAuditoria::LOGOUT, $user);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $destino = (string) $request->query('post_logout_redirect_uri', '');
        if ($destino !== '' && $this->destinoPermitido($destino)) {
            return redirect()->away($destino);
        }

        return view('sesion-cerrada');
    }

    private function destinoPermitido(string $destino): bool
    {
        return Sistema::where('activo', true)->whereNotNull('url_base')->pluck('url_base')
            ->contains(fn (string $base) => $destino === rtrim($base, '/') || Str::startsWith($destino, rtrim($base, '/').'/'));
    }
}
