<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Miles\PlatformClient\Access\CurrentAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages and actions of a plan feature (RequireFeature::class.':key').
 * Without the feature they are not found, as if not installed.
 */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_if(app(CurrentAccess::class)->get()?->feature($feature) === null, 404);

        return $next($request);
    }
}
