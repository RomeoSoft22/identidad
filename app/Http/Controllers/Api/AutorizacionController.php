<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventoAuditoria;
use App\Models\Sistema;
use App\Support\Auditoria;
use App\Support\SesionesSso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Bridge\User as UsuarioOAuth;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Equivalente JSON de /oauth/authorize para identidad-web (Angular).
 * identidad-web le reenvía los parámetros que recibió del sistema cliente y, según la respuesta,
 * muestra el login, la pantalla de sin acceso o redirige al callback del sistema con el código.
 */
class AutorizacionController extends Controller
{
    public function __invoke(
        Request $request,
        ServerRequestInterface $psrRequest,
        ResponseInterface $psrResponse,
        AuthorizationServer $server,
    ): JsonResponse {
        try {
            // Valida client_id, redirect_uri exacta, response_type, state y PKCE.
            $authRequest = $server->validateAuthorizationRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return $this->solicitudInvalida($e->getMessage());
        }

        $sistema = Sistema::porCliente($authRequest->getClient()->getIdentifier());
        if (! $sistema?->activo) {
            return $this->solicitudInvalida('El sistema no está habilitado.');
        }
        $datosSistema = ['codigo' => $sistema->codigo, 'nombre' => $sistema->nombre];

        // Exige cuenta activa y sesión SSO vigente (no cerrada, vencida ni revocada).
        $user = SesionesSso::usuarioVigente($request);
        if (! $user) {
            return response()->json(['codigo' => 'login_requerido', 'sistema' => $datosSistema], 401);
        }

        if ($user->rolesEn($sistema) === []) {
            Auditoria::registrar(EventoAuditoria::ACCESO_DENEGADO, $user, $sistema, ['etapa' => 'authorize']);

            return response()->json([
                'codigo' => 'sin_acceso',
                'sistema' => $datosSistema,
                'usuario' => ['nombre' => $user->nombreCompleto(), 'email' => $user->correo],
            ], 403);
        }

        $authRequest->setUser(new UsuarioOAuth($user->getAuthIdentifier()));
        $authRequest->setAuthorizationApproved(true);

        try {
            $respuesta = $server->completeAuthorizationRequest($authRequest, $psrResponse);
        } catch (OAuthServerException $e) {
            return $this->solicitudInvalida($e->getMessage());
        }

        SesionesSso::registrarAcceso($user, $sistema, $request);

        return response()->json(['redirect_to' => $respuesta->getHeaderLine('Location'), 'sistema' => $datosSistema]);
    }

    private function solicitudInvalida(string $detalle): JsonResponse
    {
        // El motivo técnico (de la librería OAuth) va solo al log, nunca al navegador.
        Log::notice('authorize rechazado: '.$detalle, ['ip' => request()->ip()]);

        return response()->json([
            'codigo' => 'solicitud_invalida',
            'message' => 'El enlace de ingreso no es válido o está incompleto. Vuelve al sistema e inténtalo de nuevo.',
        ], 400);
    }
}
