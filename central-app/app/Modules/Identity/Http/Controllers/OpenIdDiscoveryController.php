<?php

namespace App\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * OpenID Provider metadata (OpenID Connect Discovery 1.0, section 3).
 * Advertises only what the platform accepts: code flow, PKCE S256, RS256.
 */
class OpenIdDiscoveryController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'issuer' => (string) config('openid.issuedBy'),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'jwks_uri' => route('openid.jwks'),
            'scopes_supported' => array_keys((array) config('openid.passport.tokens_can')),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'nonce', 'name', 'updated_at', 'email', 'email_verified'],
            'prompt_values_supported' => ['none', 'login', 'consent'],
        ])->header('Cache-Control', 'public, max-age=3600');
    }
}
