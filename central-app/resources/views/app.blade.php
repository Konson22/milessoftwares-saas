<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark']) @if ($forcedAppearance ?? null) data-forced-appearance="{{ $forcedAppearance }}" style="color-scheme: {{ $forcedAppearance }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon-96x96.png" type="image/png" sizes="96x96">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <meta name="theme-color" content="#05451b">

        @fonts

        @php
            // Public site pages only: never the signed-in hosts.
            $publicSite = str_starts_with($page['component'], 'public/');
            $siteVerification = $publicSite ? config('services.google.site_verification') : null;
            $tagManagerId = $publicSite ? config('services.google.tag_manager_id') : null;
            // With Tag Manager, GA4 is configured in the container instead.
            $analyticsId = $publicSite && ! $tagManagerId ? config('services.google.analytics_id') : null;
        @endphp
        @if ($siteVerification)
            <meta name="google-site-verification" content="{{ $siteVerification }}">
        @endif
        @if ($tagManagerId)
            {{--
                Google Tag Manager: Google's container snippet, with gtm.js
                fetched once the page has loaded and the browser is idle so it
                never holds up the page (events pushed before then wait in
                dataLayer). Inertia changes pages with history.pushState, so
                GA4 page views come from GA4's enhanced measurement (page
                changes on browser history events) or a History Change
                trigger in the container. The nonce lets the container's
                Custom HTML tags run under the CSP.
            --}}
            <script nonce="{{ Vite::cspNonce() }}">
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
                (function () {
                    function load() {
                        var s = document.createElement('script');
                        s.async = true;
                        s.nonce = @json(Vite::cspNonce());
                        s.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(@json($tagManagerId));
                        document.head.appendChild(s);
                    }
                    function idle() {
                        'requestIdleCallback' in window ? requestIdleCallback(load, { timeout: 3000 }) : setTimeout(load, 1500);
                    }
                    document.readyState === 'complete' ? idle() : addEventListener('load', idle, { once: true });
                })();
            </script>
        @endif
        @if ($analyticsId)
            {{--
                Google Analytics 4. gtag() calls queue in dataLayer; gtag.js
                itself is fetched once the page has loaded and the browser is
                idle, so it never holds up the page. Page views are sent by
                resources/js/sites/public/components/analytics.tsx, on the first
                render and on every Inertia navigation.
            --}}
            <script nonce="{{ Vite::cspNonce() }}">
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());
                gtag('config', @json($analyticsId), { send_page_view: false });
                (function () {
                    function load() {
                        var s = document.createElement('script');
                        s.async = true;
                        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(@json($analyticsId));
                        document.head.appendChild(s);
                    }
                    function idle() {
                        'requestIdleCallback' in window ? requestIdleCallback(load, { timeout: 3000 }) : setTimeout(load, 1500);
                    }
                    document.readyState === 'complete' ? idle() : addEventListener('load', idle, { once: true });
                })();
            </script>
        @endif

        @viteReactRefresh
        @php
            // Public site pages carry the stylesheet in the HTML: they are
            // server-rendered, so a linked stylesheet would hold back the
            // first paint for a whole extra request (Lighthouse:
            // render-blocking requests). Signed-in hosts keep the cached link.
            $inlineCss = str_starts_with($page['component'], 'public/') && ! Vite::isRunningHot();
        @endphp
        @if ($inlineCss)
            <style>{!! Vite::content('resources/css/app.css') !!}</style>
        @endif
        @vite([...($inlineCss ? [] : ['resources/css/app.css']), 'resources/js/app.tsx', \App\Support\Inertia\SitePages::sitePath($page['component']), \App\Support\Inertia\SitePages::path($page['component'])])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        @if ($tagManagerId)
            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ urlencode($tagManagerId) }}" height="0" width="0" style="display:none;visibility:hidden" title="Google Tag Manager"></iframe></noscript>
        @endif
        <x-inertia::app />
    </body>
</html>
