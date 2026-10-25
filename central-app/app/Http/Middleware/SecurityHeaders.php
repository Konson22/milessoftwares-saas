<?php

namespace App\Http\Middleware;

use App\Support\Hosts\PlatformHosts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers for every host. Inline scripts run only with the
 * per-request nonce, which Vite also puts on the tags it generates.
 */
class SecurityHeaders
{
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

        if (config('platform.security_headers.csp')) {
            $name = config('platform.security_headers.csp_report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            if (! $headers->has($name)) {
                $headers->set($name, $this->policy($nonce, $request));
            }
        }

        if (config('platform.security_headers.hsts') && $request->isSecure()) {
            $value = 'max-age='.(int) config('platform.security_headers.hsts_max_age');
            if (config('platform.security_headers.hsts_include_subdomains')) {
                $value .= '; includeSubDomains';
            }
            $headers->set('Strict-Transport-Security', $value);
        }

        return $response;
    }

    private function policy(string $nonce, Request $request): string
    {
        // While `npm run dev` is running, assets come from the Vite dev server.
        $dev = '';
        $devSocket = '';
        if (Vite::isRunningHot()) {
            $origin = rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');
            $dev = ' '.$origin;
            $devSocket = ' '.preg_replace('#^http#', 'ws', $origin);
        }

        // Meta Pixel only runs on the public marketing site.
        $fbScript = '';
        $fbImg = '';
        $fbConnect = '';
        if (strtolower($request->getHost()) === PlatformHosts::root()) {
            $fbScript = ' https://connect.facebook.net';
            $fbImg = ' https://www.facebook.com';
            $fbConnect = ' https://www.facebook.com https://connect.facebook.net';
        }

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$dev}{$fbScript}",
            "style-src 'self' 'unsafe-inline'{$dev}",
            "img-src 'self' data: blob:{$dev}{$fbImg}",
            "font-src 'self' data:{$dev}",
            "connect-src 'self'{$dev}{$devSocket}{$fbConnect}",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
        ];

        $reportUri = config('platform.security_headers.csp_report_uri');
        if (is_string($reportUri) && $reportUri !== '') {
            $directives[] = 'report-uri '.$reportUri;
        }

        return implode('; ', $directives);
    }
}
