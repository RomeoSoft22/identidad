<?php

namespace App\Http\Controllers;

use App\Support\Llaves;
use Illuminate\Http\JsonResponse;

class DescubrimientoController extends Controller
{
    public function jwks(): JsonResponse
    {
        return response()->json(['keys' => [Llaves::jwk()]])
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function configuracion(): JsonResponse
    {
        $iss = config('identidad.issuer');

        return response()->json([
            'issuer' => $iss,
            'authorization_endpoint' => $iss.'/oauth/authorize',
            'token_endpoint' => $iss.'/oauth/token',
            'jwks_uri' => $iss.'/.well-known/jwks.json',
            'end_session_endpoint' => $iss.'/logout',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'claims_supported' => ['iss', 'sub', 'aud', 'exp', 'iat', 'jti', 'name', 'email', 'roles'],
        ])->header('Cache-Control', 'public, max-age=3600');
    }
}
