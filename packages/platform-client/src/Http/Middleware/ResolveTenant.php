<?php

namespace Miles\PlatformClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * platform.tenant: the organization comes from the workspace host,
 * {slug}.<root_domain>. Other hosts, deeper subdomains and organizations
 * this application cannot see answer 404; a suspended organization 403.
 *
 * A workspace that changed its address is still found at the old one while
 * the platform redirects it (the platform answers with the current slug).
 * A browser is sent to the new address; an app calling the API (which
 * would lose its token across hosts) is told where the workspace is now:
 * 421 with code "workspace_moved" and the slug, so it can call there.
 */
final class ResolveTenant
{
    /** The platform's slug rules (central SlugRules): a letter first, no double hyphen, a DNS label. */
    private const SLUG = '/^[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public const MOVED = 'workspace_moved';

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PlatformApi $api,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = self::slugFor($request->getHost(), $this->config->rootDomain());
        abort_if($slug === null, 404);

        try {
            $organization = $this->api->organization($slug);
        } catch (NotVisible) {
            abort(404);
        } catch (PlatformUnavailable $e) {
            Log::error('Platform unavailable while resolving the tenant', ['slug' => $slug, 'error' => $e->getMessage()]);
            abort(503);
        }

        if ($organization->slug !== '' && $organization->slug !== $slug) {
            return $this->moved($request, $organization->slug);
        }

        abort_unless($organization->isActive(), 403, 'This workspace is not active.');

        $this->tenant->set($organization);

        return $next($request);
    }

    public static function slugFor(string $host, string $rootDomain): ?string
    {
        $host = strtolower(rtrim($host, '.'));
        $suffix = '.'.$rootDomain;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        return preg_match(self::SLUG, $label) === 1 && ! str_contains($label, '--') ? $label : null;
    }

    /**
     * The same page at the workspace's current address.
     */
    private function moved(Request $request, string $slug): Response
    {
        $port = $request->getPort();
        $default = $request->isSecure() ? 443 : 80;
        $url = $request->getScheme().'://'.$slug.'.'.$this->config->rootDomain().($port !== null && (int) $port !== $default ? ':'.$port : '').$request->getRequestUri();

        // An Inertia visit: a full page load at the new address.
        if ($request->hasHeader('X-Inertia')) {
            return response('', 409)->header('X-Inertia-Location', $url);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "This workspace's address changed to {$slug}.",
                'code' => self::MOVED,
                'slug' => $slug,
            ], 421);
        }

        // 308 keeps the method and body of a form sent to the old address.
        return redirect()->away($url, $request->isMethodSafe() ? 301 : 308);
    }
}
