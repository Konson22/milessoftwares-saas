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
 */
final class ResolveTenant
{
    private const SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

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

        return preg_match(self::SLUG, $label) === 1 ? $label : null;
    }
}
