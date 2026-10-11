<?php

namespace Miles\PlatformClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * platform.access (after platform.tenant): a signed-in member of this
 * workspace whose effective access allows this application. Re-checked on
 * every request against the cached answer, so revocations take effect
 * within the cache window, or at once when the webhook arrives.
 */
final class RequirePlatformAccess
{
    public const SESSION_TENANT = 'platform-client.tenant_id';

    public function __construct(
        private readonly PlatformApi $api,
        private readonly CurrentTenant $tenant,
        private readonly CurrentAccess $access,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenant->require();
        $user = Auth::user();

        // A session only ever belongs to the workspace it signed in to.
        if ($user === null || $request->session()->get(self::SESSION_TENANT) !== $tenant->id) {
            if ($user !== null) {
                Auth::logout();
                $request->session()->invalidate();
            }

            return redirect()->guest(route('platform.login'));
        }

        try {
            $access = $this->api->effectiveAccess($tenant->id, (string) $user->getAuthIdentifier());
        } catch (NotVisible) {
            abort(403);
        } catch (PlatformUnavailable $e) {
            Log::error('Platform unavailable while checking access', ['organization' => $tenant->id, 'error' => $e->getMessage()]);
            abort(503);
        }

        abort_unless($access->allowed, 403, 'You do not have access to this application.');

        $this->access->set($access);

        return $next($request);
    }
}
