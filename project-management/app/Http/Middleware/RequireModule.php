<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a page of a module this organization has switched off, whoever asks and
 * whatever their permissions: hiding the link alone would leave the page open.
 */
final class RequireModule
{
    public function __construct(private readonly CurrentOrganization $organizations) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->organizations->require()->hasModule($module), 403, 'This part of the product is switched off for this organization.');

        return $next($request);
    }
}
