<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform rules on top of the OAuth server for GET /oauth/authorize:
 * authorization code flow only, PKCE with S256, a `state`, and a `nonce`
 * whenever an id_token is requested.
 *
 * Rejections are rendered here rather than redirected to the client, because
 * the redirect URI has not been validated yet at this point.
 */
class ValidateAuthorizationRequest
{
    private const MIN_RANDOM_LENGTH = 16;

    public function handle(Request $request, Closure $next): Response
    {
        $error = $this->firstError($request);

        if ($error !== null) {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => $error,
            ], 400);
        }

        return $next($request);
    }

    private function firstError(Request $request): ?string
    {
        if ($request->query('response_type') !== 'code') {
            return 'Only response_type=code is supported.';
        }

        if (! is_string($request->query('code_challenge')) || $request->query('code_challenge') === '') {
            return 'PKCE is required: code_challenge is missing.';
        }

        if ($request->query('code_challenge_method') !== 'S256') {
            return 'PKCE code_challenge_method must be S256.';
        }

        if (! $this->isRandomValue($request->query('state'))) {
            return 'state is required and must be at least '.self::MIN_RANDOM_LENGTH.' characters.';
        }

        $scopes = explode(' ', (string) $request->query('scope', ''));

        if (in_array('openid', $scopes, true) && ! $this->isRandomValue($request->query('nonce'))) {
            return 'nonce is required for OpenID Connect requests and must be at least '.self::MIN_RANDOM_LENGTH.' characters.';
        }

        return null;
    }

    private function isRandomValue(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= self::MIN_RANDOM_LENGTH && strlen($value) <= 512;
    }
}
