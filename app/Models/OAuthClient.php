<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;

/**
 * Todos los clientes son sistemas propios de Juarez Campos: no hay pantalla de consentimiento.
 * El permiso real lo da la matrícula, que se valida en AutorizacionController y en cada emisión de token.
 */
class OAuthClient extends Client
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return true;
    }
}
