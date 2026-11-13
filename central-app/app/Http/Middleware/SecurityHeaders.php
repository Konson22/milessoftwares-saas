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

        // Only the public site (apex and www) is for search engines. Every
        // other host (auth., admin., app. and each workspace) is signed-in
        // or account-specific: never indexed, even when linked from elsewhere.
        if (! in_array(strtolower($request->getHost()), [PlatformHosts::root(), PlatformHosts::www()], true)) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

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

        // Meta Pixel and Google tags only run on the public marketing site.
        $trackingScript = '';
        $trackingImg = '';
        $trackingConnect = '';
        $trackingFrame = '';
        if (strtolower($request->getHost()) === PlatformHosts::root()) {
            $trackingScript = ' https://connect.facebook.net';
            $trackingImg = ' https://www.facebook.com';
            $trackingConnect = ' https://www.facebook.com https://connect.facebook.net';

            // Google Tag Manager or Analytics 4, when configured
            // (app.blade.php): Google's documented hosts for gtm.js and
            // gtag.js and GA4's collection endpoints, which a container's
            // GA4 tag uses too. Other vendors added in the container need
            // their hosts here as well.
            $tagManager = filled(config('services.google.tag_manager_id'));
            if ($tagManager) {
                $trackingFrame = ' https://www.googletagmanager.com';
            }

            if ($tagManager || filled(config('services.google.analytics_id'))) {
                $trackingScript .= ' https://www.googletagmanager.com';
                $trackingImg .= ' https://*.google-analytics.com https://*.googletagmanager.com';
                $trackingConnect .= ' https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com';
            }
        }

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$dev}{$trackingScript}",
            "style-src 'self' 'unsafe-inline'{$dev}",
            // Google profile pictures (users.avatar_url) come from googleusercontent.com.
            "img-src 'self' data: blob: https://*.googleusercontent.com{$dev}{$trackingImg}",
            "font-src 'self' data:{$dev}",
            "connect-src 'self'{$dev}{$devSocket}{$trackingConnect}",
            "frame-src 'self'{$trackingFrame}",
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
