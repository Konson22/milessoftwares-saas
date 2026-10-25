<?php

namespace App\Modules\Integration\Http;

use App\Models\User;
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
 * Authenticates a person in an application's own app (desktop, mobile): a
 * user's access token issued to an active public client of an application.
 * The user (active, email verified, as the dashboard requires) and the
 * application are attached to the request.
 *
 * Usage: ->middleware('api.user')
 */
class AuthenticateAppUser
{
    public function __construct(private readonly ResourceServer $server) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $psr = $this->server->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            return $this->deny('invalid_token', 401);
        }

        $userId = (string) $psr->getAttribute('oauth_user_id');
        $clientId = (string) $psr->getAttribute('oauth_client_id');

        // A product backend's client-credentials token names no one.
        if ($userId === '' || $userId === $clientId) {
            return $this->deny('user_token_required', 403);
        }

        $applicationId = DB::table('application_clients')
            ->where('oauth_client_ref', $clientId)
            ->where('client_type', 'public')
            ->where('status', 'active')
            ->value('application_id');

        $application = $applicationId === null ? null : Application::query()->find($applicationId);

        if ($application === null) {
            return $this->deny('unknown_client', 403);
        }

        $user = User::query()->find($userId);

        if (! $user instanceof User || $user->status !== 'active') {
            return $this->deny('invalid_token', 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'error' => 'email_unverified',
                'message' => __('Confirm your email address first: open the link we sent to :email.', ['email' => $user->email]),
            ], 403);
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_application', $application);

        return $next($request);
    }

    private function deny(string $error, int $status): JsonResponse
    {
        return response()->json(['error' => $error], $status, $status === 401 ? ['WWW-Authenticate' => 'Bearer'] : []);
    }
}
