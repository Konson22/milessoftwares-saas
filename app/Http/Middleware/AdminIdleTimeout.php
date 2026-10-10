<?php

namespace App\Http\Middleware;

use App\Support\Hosts\PlatformHosts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin sessions end after a short period without activity, independent of
 * the general session lifetime.
 */
class AdminIdleTimeout
{
    private const SESSION_KEY = 'admin.last_activity';

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->guest()) {
            return $next($request);
        }

        $limit = (int) config('platform.admin.idle_minutes', 30) * 60;
        $last = $request->session()->get(self::SESSION_KEY);

        if (is_int($last) && now()->getTimestamp() - $last > $limit) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->away(PlatformHosts::url(PlatformHosts::admin(), 'auth/login'));
        }

        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());

        return $next($request);
    }
}
