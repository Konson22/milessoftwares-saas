<?php

namespace Miles\PlatformClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening for product pages, the same policy as the platform.
 * Products share the workspace's origin ({slug}.<root>), so a script
 * injected into a product could act in the workspace: inline scripts run
 * only with the per-request nonce, which Vite also puts on its tags.
 *
 * Append it to the global middleware: $middleware->append(SecurityHeaders::class).
 */
final class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (config('platform-client.security_headers.csp', true)) {
            $name = config('platform-client.security_headers.csp_report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            if (! $headers->has($name)) {
                $headers->set($name, $this->policy($nonce));
            }
        }

        if (config('platform-client.security_headers.hsts') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age='.(int) config('platform-client.security_headers.hsts_max_age', 63072000).'; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        // While `npm run dev` is running, assets come from the Vite dev server.
        $dev = '';
        $devSocket = '';
        if (Vite::isRunningHot()) {
            $origin = rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');
            $dev = ' '.$origin;
            $devSocket = ' '.preg_replace('#^http#', 'ws', $origin);
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$dev}",
            "style-src 'self' 'unsafe-inline'{$dev}",
            "img-src 'self' data: blob:{$dev}",
            "font-src 'self' data:{$dev}",
            "connect-src 'self'{$dev}{$devSocket}",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
