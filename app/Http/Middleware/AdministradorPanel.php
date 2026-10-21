<?php

namespace App\Http\Middleware;

use App\Models\Sistema;
use App\Models\Usuario;
use App\Support\Llaves;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Protege /api/admin/*: exige el JWT que identidad emitió para el panel (aud = PANEL),
 * vigente y no revocado, de un usuario activo que hoy siga teniendo el rol ADMIN en PANEL.
 * Los permisos se vuelven a leer en cada petición: quitar la matrícula surte efecto de inmediato.
 */
class AdministradorPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $jwt = (string) $request->bearerToken();
        if ($jwt === '') {
            return $this->rechazar('Inicia sesión en el panel.', 401);
        }

        try {
            JWT::$leeway = 30;
            $claims = (array) JWT::decode($jwt, new Key(Llaves::publicaPem(), 'RS256'));
        } catch (Throwable) {
            return $this->rechazar('La sesión del panel no es válida o venció.', 401);
        }

        $codigoPanel = config('identidad.sistema_panel');
        if (($claims['iss'] ?? null) !== config('identidad.issuer') || ! in_array($codigoPanel, (array) ($claims['aud'] ?? []), true)) {
            return $this->rechazar('El token no es para el panel.', 401);
        }

        $revocado = Passport::token()->newQuery()->whereKey($claims['jti'] ?? '')->value('revoked');
        if ($revocado === null || (bool) $revocado) {
            return $this->rechazar('La sesión del panel fue cerrada.', 401);
        }

        $admin = Usuario::where('uuid', $claims['sub'] ?? '')->first();
        $panel = Sistema::where('codigo', $codigoPanel)->first();
        if (! $admin || $admin->estado !== Usuario::ACTIVO || ! $panel?->activo || ! in_array('ADMIN', $admin->rolesEn($panel), true)) {
            return $this->rechazar('No tienes permiso de administrador en el panel.', 403);
        }

        $request->attributes->set('admin', $admin);

        return $next($request);
    }

    private function rechazar(string $mensaje, int $estado): Response
    {
        return response()->json(['message' => $mensaje], $estado);
    }
}
