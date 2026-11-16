<?php

namespace Miles\PlatformClient\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\SignIn\KeySet;
use Miles\PlatformClient\SignIn\SignInFailed;
use Miles\SharedModuleKit\EventToken;
use Miles\SharedModuleKit\EventTokenVerifier;
use Miles\SharedModuleKit\InvalidEventToken;
use Miles\SharedModuleKit\Jwks;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A paired product reading this product's integration API (ADR-0019 §9),
 * server to server: no session, no CSRF, no workspace host. The caller
 * proves itself with a platform-signed read token (scope integration.read)
 * whose audience is this application, whose subject is the one product
 * allowed here, and which is bound to one organization (and, when the read
 * is on behalf of someone, one acting member). The platform issues it only
 * while the organization is active and entitled to both products.
 *
 * The verified token is on the request (attribute integration.token); the
 * controller still decides what that organization, and that member in this
 * product's own catalog, may read.
 *
 * Usage: ->middleware('platform.integration:mel')
 *
 *   401 invalid_token   403 source_not_allowed
 */
final class RequireIntegrationToken
{
    public const TOKEN = 'integration.token';

    public const CORRELATION = 'integration.correlation_id';

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly KeySet $keys,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $source): Response
    {
        $given = (string) $request->header('X-Correlation-Id', '');
        $correlationId = preg_match('/^[A-Za-z0-9-]{8,64}$/', $given) === 1 ? $given : (string) Str::uuid7();
        $request->attributes->set(self::CORRELATION, $correlationId);

        try {
            $jwt = (string) preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization', ''));
            $token = (new EventTokenVerifier($this->config->issuer(), $this->keys->publicKey(Jwks::kidOf($jwt)), $this->config->application(), null, 'integration.read'))
                ->verify($jwt);
        } catch (InvalidEventToken|SignInFailed|RuntimeException $e) {
            return $this->refuse($request, 401, 'invalid_token', $e->getMessage(), $correlationId);
        }

        if ($token->sourceApp !== $source) {
            return $this->refuse($request, 403, 'source_not_allowed', "Only {$source} reads this API, not {$token->sourceApp}.", $correlationId, $token);
        }

        $request->attributes->set(self::TOKEN, $token);

        Log::info('Integration request', [
            'source' => $token->sourceApp,
            'organization' => $token->organizationId,
            'acting_user' => $token->actingUser,
            'path' => $request->path(),
            'correlation_id' => $correlationId,
        ]);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }

    private function refuse(Request $request, int $status, string $error, string $detail, string $correlationId, ?EventToken $token = null): JsonResponse
    {
        Log::warning('Integration request refused', [
            'error' => $error,
            'detail' => $detail,
            'source' => $token?->sourceApp,
            'organization' => $token?->organizationId,
            'path' => $request->path(),
            'correlation_id' => $correlationId,
        ]);

        return response()->json(['error' => $error], $status, ['X-Correlation-Id' => $correlationId]);
    }
}
