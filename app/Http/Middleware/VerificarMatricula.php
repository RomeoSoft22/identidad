<?php

namespace App\Http\Middleware;

use App\Models\EventoAuditoria;
use App\Models\Sistema;
use App\Support\Auditoria;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corre antes de /oauth/authorize. Si el usuario ya inició sesión pero no tiene
 * matrícula vigente en el sistema que pide acceso, se corta aquí y no se emite código.
 * Si no hay sesión, deja pasar: Passport redirige al login y se vuelve aquí después.
 */
class VerificarMatricula
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $sistema = Sistema::porCliente($request->query('client_id'));
        if (! $sistema) {
            // Cliente inexistente: Passport responde el error OAuth correspondiente.
            return $next($request);
        }

        if (! $sistema->activo || $user->rolesEn($sistema) === []) {
            Auditoria::registrar(EventoAuditoria::ACCESO_DENEGADO, $user, $sistema, ['etapa' => 'authorize']);

            return response()->view('sin-acceso', ['sistema' => $sistema, 'user' => $user], 403);
        }

        return $next($request);
    }
}
