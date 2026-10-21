<?php

namespace App\Support;

use Laravel\Passport\Passport;
use RuntimeException;

/**
 * Expone la llave pública de Passport como JWK (RFC 7517) y calcula su "kid"
 * con la huella RFC 7638, para que cambie sola cuando se rotan las llaves.
 */
class Llaves
{
    private static ?array $jwk = null;

    public static function jwk(): array
    {
        if (self::$jwk !== null) {
            return self::$jwk;
        }

        $pem = self::publicaPem();
        $detalles = $pem ? openssl_pkey_get_details(openssl_pkey_get_public($pem)) : false;
        if (! $detalles || ($detalles['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('No se pudo leer la llave pública RSA de Passport.');
        }

        $n = self::base64url($detalles['rsa']['n']);
        $e = self::base64url($detalles['rsa']['e']);
        $kid = self::base64url(hash('sha256', json_encode(['e' => $e, 'kty' => 'RSA', 'n' => $n]), true));

        return self::$jwk = ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $n, 'e' => $e];
    }

    /** Llave pública RSA en formato PEM (la misma que publica el JWKS). */
    public static function publicaPem(): string
    {
        return (string) (config('passport.public_key') ?: @file_get_contents(Passport::keyPath('oauth-public.key')));
    }

    public static function kid(): string
    {
        return self::jwk()['kid'];
    }

    public static function olvidar(): void
    {
        self::$jwk = null;
    }

    private static function base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
