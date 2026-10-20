<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inertia submits forms with XHR, which follows redirects itself. A redirect
 * to another platform host (auth. to app., a workspace, oauth/authorize on to
 * a client callback) is then a cross-origin request the browser blocks, and
 * the page just stays where it was. For Inertia requests such redirects become
 * Inertia::location(), a full page visit.
 */
class RedirectAcrossHostsWithInertia
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->header('X-Inertia') || ! $response instanceof RedirectResponse) {
            return $response;
        }

        $target = $response->getTargetUrl();
        $host = parse_url($target, PHP_URL_HOST);

        if (! is_string($host) || strcasecmp($host, $request->getHost()) === 0) {
            return $response;
        }

        return Inertia::location($target);
    }
}
