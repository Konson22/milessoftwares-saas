<?php

namespace App\Http\Middleware;

use App\Modules\Organizations\Models\OrganizationSlug;
use App\Modules\Organizations\Support\SlugRules;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves {slug}.<root> to an organization and binds the tenant context.
 *
 * Unknown, malformed, reserved, pending and archived hosts all produce the
 * same plain 404 so the response never reveals anything about other tenants.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $label = $route?->parameter('tenant');
        $slug = is_string($label) ? strtolower($label) : '';

        // The route parameter is only routing plumbing; controllers read the
        // tenant from TenantContext, never from the URL.
        $route?->forgetParameter('tenant');

        if (! SlugRules::isWellFormed($slug) || PlatformHosts::isReserved($slug)) {
            abort(404);
        }

        $record = OrganizationSlug::query()
            ->with('organization')
            ->where('slug', $slug)
            ->first();

        if ($record === null || $record->retired_at !== null && ! $record->redirectsToPrimary()) {
            abort(404);
        }

        $organization = $record->organization;

        if (! $organization->status->resolvesWorkspace()) {
            abort(404);
        }

        if (! $record->is_primary) {
            $primary = $organization->primarySlug;
            abort_if($primary === null, 404);

            return redirect()->away(
                PlatformHosts::url(PlatformHosts::workspace($primary->slug), $request->getRequestUri()),
                301,
            );
        }

        $this->tenant->set($organization);

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }
}
