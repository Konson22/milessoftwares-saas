<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Memberships\Support\CurrentOrganization;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the app host, binds the tenant context to the user's current
 * organization after re-validating the membership on every request. With no
 * valid current organization, the request runs without a tenant context and
 * tenant-owned models fail closed.
 */
class ResolveCurrentOrganization
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $membership = $user instanceof User ? $this->current->resolve($request, $user) : null;

        if ($membership === null) {
            return $next($request);
        }

        $request->attributes->set('membership', $membership);
        $this->tenant->set($membership->organization);

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }
}
