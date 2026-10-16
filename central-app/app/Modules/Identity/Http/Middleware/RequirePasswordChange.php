<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Models\User;
use App\Support\Hosts\HostType;
use App\Support\Hosts\PlatformHosts;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone signed in with a temporary password does nothing else until they
 * have chosen their own: every page sends them to the auth host's "choose a
 * password" page, which then continues where they were going (for example
 * the sign-in into a workspace or product). Signing out stays possible.
 */
class RequirePasswordChange
{
    /** Routes that stay open while the change is pending. */
    private const ALLOWED = ['password.setup', 'password.setup.store', 'logout', 'signout'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->mustChangePassword() || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        $onAuthHost = PlatformHosts::classify($request->getHost())['type'] === HostType::Auth;

        if ($onAuthHost && $request->isMethod('GET') && ! $request->expectsJson()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        $url = PlatformHosts::url(PlatformHosts::auth(), 'password/setup');

        // Inertia visits need a full page load to change host.
        return $request->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
    }
}
