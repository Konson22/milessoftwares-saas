<?php

namespace App\Http\Middleware;

use App\Support\Hosts\PlatformHosts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * The public site (the apex) is always light, whatever the visitor's
     * saved or system preference; the applications follow it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $forced = strtolower($request->getHost()) === PlatformHosts::root() ? 'light' : null;

        View::share('forcedAppearance', $forced);
        View::share('appearance', $forced ?? $request->cookie('appearance') ?? 'system');

        return $next($request);
    }
}
