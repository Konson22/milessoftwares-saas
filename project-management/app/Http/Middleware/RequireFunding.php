<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the parts only an organization that funds others uses (the funding
 * marketplace). Runs after ResolveOrganization.
 */
class RequireFunding
{
    public function __construct(private readonly CurrentOrganization $organizations) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->organizations->require()->isDonor(), 404);

        return $next($request);
    }
}
