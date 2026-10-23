<?php

namespace Miles\PlatformClient\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\SignIn\AppTokens;
use Miles\PlatformClient\SignIn\SignInFailed;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * platform.token (after platform.tenant): the API's counterpart of
 * platform.access. The mobile app's access token (Authorization: Bearer)
 * names the platform user; they must be a member of this workspace whose
 * effective access allows the application, checked on every request.
 * No session and no cookie: the user is set for this request only.
 *
 * The user must have started a session (POST <path>/api/session), which
 * records their name and email here; until then the answer is 401
 * "session_required".
 */
final class RequirePlatformToken
{
    public function __construct(
        private readonly AppTokens $tokens,
        private readonly PlatformApi $api,
        private readonly CurrentTenant $tenant,
        private readonly CurrentAccess $access,
        private readonly PlatformConfig $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->config->mobileClientId() === null, 404);
        $tenant = $this->tenant->require();

        try {
            $subject = $this->tokens->subject((string) $request->bearerToken());
        } catch (SignInFailed) {
            return self::unauthorized('invalid_token', 'Sign in again.');
        }

        try {
            $access = $this->api->effectiveAccess($tenant->id, $subject);
        } catch (NotVisible) {
            abort(403, 'You do not have access to this application.');
        } catch (PlatformUnavailable $e) {
            Log::error('Platform unavailable while checking access', ['organization' => $tenant->id, 'error' => $e->getMessage()]);
            abort(503);
        }

        abort_unless($access->allowed, 403, 'You do not have access to this application.');
        $this->access->set($access);

        // The session endpoint creates the user; everything else needs them.
        if (! $request->routeIs($this->config->routeName('api.session'))) {
            $model = $this->config->userModel();
            $user = $model::query()->find($subject);

            if ($user === null) {
                return self::unauthorized('session_required', 'Sign in again.');
            }

            Auth::setUser($user);
        }

        return $next($request);
    }

    public static function unauthorized(string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], 401, ['WWW-Authenticate' => 'Bearer error="'.$code.'"']);
    }
}
