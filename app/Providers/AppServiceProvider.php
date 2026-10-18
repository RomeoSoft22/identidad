<?php

namespace App\Providers;

use App\Models\OAuthClient;
use App\OAuth\AccessToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::ignoreRoutes();
    }

    public function boot(): void
    {
        Passport::$deviceCodeGrantEnabled = false;
        Passport::useClientModel(OAuthClient::class);
        Passport::useAccessTokenEntity(AccessToken::class);
        Passport::tokensExpireIn(now()->addMinutes(config('identidad.access_token_minutos')));
        Passport::refreshTokensExpireIn(now()->addMinutes(config('identidad.refresh_token_minutos')));

        // Nunca se muestra pantalla de consentimiento (OAuthClient::skipsAuthorization), pero Passport exige registrarla.
        Passport::authorizationView(fn () => abort(403));
    }
}
