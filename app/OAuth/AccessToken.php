<?php

namespace App\OAuth;

use App\Models\EventoAuditoria;
use App\Models\Sistema;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Llaves;
use DateTimeImmutable;
use Lcobucci\JWT\Token;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * JWT que emite identidad. Reemplaza los claims por defecto de Passport:
 *  - aud   = código del sistema (ISTQB, PANEL...) en lugar del UUID del cliente
 *  - sub   = UUID estable del usuario en lugar del id numérico
 *  - roles = roles con matrícula vigente en ese sistema
 *  - kid en la cabecera para poder rotar llaves
 *
 * Se ejecuta en cada emisión (canje de código y renovación con refresh token),
 * así que una cuenta bloqueada o sin matrícula deja de recibir tokens nuevos.
 */
class AccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait, EntityTrait, TokenEntityTrait;

    /**
     * Misma firma que Laravel\Passport\Bridge\AccessToken, que es como Passport lo construye.
     *
     * @param  \League\OAuth2\Server\Entities\ScopeEntityInterface[]  $scopes
     */
    public function __construct(?string $userIdentifier, array $scopes, ClientEntityInterface $client)
    {
        if (! is_null($userIdentifier)) {
            $this->setUserIdentifier($userIdentifier);
        }

        foreach ($scopes as $scope) {
            $this->addScope($scope);
        }

        $this->setClient($client);
    }

    private function convertToJWT(): Token
    {
        $this->initJwtConfiguration();

        $sistema = Sistema::porCliente($this->getClient()->getIdentifier());
        $user = $this->getUserIdentifier() ? User::find($this->getUserIdentifier()) : null;

        if (! $sistema?->activo || ! $user || $user->estado !== User::ACTIVO) {
            throw OAuthServerException::accessDenied('La cuenta o el sistema no están habilitados.');
        }

        $roles = $user->rolesEn($sistema);
        if ($roles === []) {
            Auditoria::registrar(EventoAuditoria::ACCESO_DENEGADO, $user, $sistema, ['etapa' => 'emision_token']);
            throw OAuthServerException::accessDenied('El usuario no tiene matrícula vigente en este sistema.');
        }

        $ahora = new DateTimeImmutable();

        Auditoria::registrar(EventoAuditoria::TOKEN_EMITIDO, $user, $sistema, ['jti' => $this->getIdentifier()]);

        return $this->jwtConfiguration->builder()
            ->withHeader('kid', Llaves::kid())
            ->issuedBy(config('identidad.issuer'))
            ->permittedFor($sistema->codigo)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($ahora)
            ->canOnlyBeUsedAfter($ahora)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($user->uuid)
            ->withClaim('name', $user->name)
            ->withClaim('email', $user->email)
            ->withClaim('roles', $roles)
            ->withClaim('scopes', $this->getScopes())
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey());
    }
}
