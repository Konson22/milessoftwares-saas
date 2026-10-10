<?php

namespace App\Modules\Integration\Http;

use App\Modules\Applications\Models\Application;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a product backend: a client-credentials access token (no
 * user), carrying the required scope, issued to an active service client of
 * an application. The application is attached to the request; every API
 * answer is limited to that application.
 *
 * Usage: ->middleware('api.client:access.read')
 */
class AuthenticateApplicationClient
{
    public function __construct(private readonly ResourceServer $server) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        try {
            $psr = $this->server->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            return $this->deny('invalid_token', 401);
        }

        $userId = $psr->getAttribute('oauth_user_id');
        $clientId = (string) $psr->getAttribute('oauth_client_id');
        $scopes = (array) $psr->getAttribute('oauth_scopes', []);

        // A user's token must not be usable as a product's service identity.
        if ($userId !== null && $userId !== '' && $userId !== $clientId) {
            return $this->deny('client_credentials_required', 403);
        }

        if (! in_array($scope, $scopes, true)) {
            return $this->deny('insufficient_scope', 403);
        }

        $applicationId = DB::table('application_clients')
            ->where('oauth_client_ref', $clientId)
            ->where('client_type', 'service')
            ->where('status', 'active')
            ->value('application_id');

        $application = $applicationId === null ? null : Application::query()->find($applicationId);

        if ($application === null) {
            return $this->deny('unknown_client', 403);
        }

        $request->attributes->set('api_application', $application);

        return $next($request);
    }

    private function deny(string $error, int $status): JsonResponse
    {
        return response()->json(['error' => $error], $status, $status === 401 ? ['WWW-Authenticate' => 'Bearer'] : []);
    }
}
