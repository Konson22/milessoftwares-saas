@php
    use App\Support\Hosts\HostType;
    use App\Support\Hosts\PlatformHosts;

    // Self-contained on purpose: no Vite, no session, no database, so the
    // page still renders when the error is in one of those. The way home
    // depends on the host the visitor is on.
    $host = request()->getHost();
    $type = PlatformHosts::classify($host)['type'];

    // Sessions are per host, and only requests through the web middleware
    // (matched routes and the fallback) have one; anything else counts as
    // signed out.
    $signedIn = rescue(fn () => request()->hasSession() && auth()->check(), false, false);

    // Signed out, the app and workspace hosts sign in through their own
    // /auth/login so the visitor comes back here.
    $signInUrl = match ($type) {
        HostType::App, HostType::Workspace => PlatformHosts::url($host, '/auth/login'),
        HostType::Admin => PlatformHosts::url(PlatformHosts::admin(), '/login'),
        default => PlatformHosts::url(PlatformHosts::auth(), '/login'),
    };
    $registerUrl = $type === HostType::Admin ? null : PlatformHosts::url(PlatformHosts::auth(), '/register');

    [$homeLabel, $homeUrl] = match (true) {
        in_array($type, [HostType::Marketing, HostType::Www, HostType::Reserved, HostType::Unknown], true) => ['Go to homepage', PlatformHosts::url(PlatformHosts::root())],
        ! $signedIn => ['Sign in', $signInUrl],
        $type === HostType::App, $type === HostType::Auth => ['Go to dashboard', PlatformHosts::url(PlatformHosts::app(), '/dashboard')],
        $type === HostType::Admin => ['Admin overview', PlatformHosts::url(PlatformHosts::admin())],
        default => ['Workspace home', PlatformHosts::url($host)],
    };
    $showSignIn = ! $signedIn && $homeUrl !== $signInUrl;
    $showRegister = ! $signedIn && $registerUrl !== null;
    $dashboardUrl = $signedIn && in_array($type, [HostType::Marketing, HostType::Www, HostType::Workspace], true)
        ? PlatformHosts::url(PlatformHosts::app(), '/dashboard')
        : null;
    $contactUrl = $type === HostType::Admin ? null : PlatformHosts::url(PlatformHosts::root(), '/contact');
    $code = trim($__env->yieldContent('code'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>@yield('title') · Miles Global Softwares</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <style>
            :root {
                --bg: #f5f7fa;
                --card: #ffffff;
                --ink: #0b1220;
                --muted: #5b6474;
                --line: #e4e8ee;
                --accent: #0b5bd6;
                --accent-hover: #0a47a8;
                --glow: #06c8ea;
                color-scheme: light;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --bg: #050f2b;
                    --card: #0a1f4e;
                    --ink: #f1f5f9;
                    --muted: #a3b0c6;
                    --line: #1c3466;
                    --accent: #3b82f6;
                    --accent-hover: #2563eb;
                    color-scheme: dark;
                }
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100svh;
                display: grid;
                place-items: center;
                padding: 24px 16px;
                background:
                    radial-gradient(40rem 24rem at 85% -10%, rgb(11 91 214 / 0.12), transparent 70%),
                    radial-gradient(36rem 22rem at -10% 110%, rgb(6 200 234 / 0.12), transparent 70%),
                    var(--bg);
                color: var(--ink);
                font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
                line-height: 1.5;
            }

            main {
                width: 100%;
                max-width: 520px;
                text-align: center;
            }

            .brand {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                color: var(--ink);
                font-weight: 600;
                font-size: 1.05rem;
                text-decoration: none;
            }

            .brand img { height: 44px; width: auto; }

            .code {
                margin: 40px 0 0;
                font-size: clamp(5rem, 22vw, 8.5rem);
                font-weight: 800;
                line-height: 1;
                letter-spacing: -0.04em;
                background: linear-gradient(135deg, var(--accent), var(--glow));
                -webkit-background-clip: text;
                background-clip: text;
                color: transparent;
            }

            h1 {
                margin: 16px 0 8px;
                font-size: 1.5rem;
                line-height: 1.25;
            }

            p.message {
                margin: 0 auto;
                max-width: 420px;
                color: var(--muted);
                font-size: 1rem;
            }

            .actions {
                margin-top: 32px;
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 10px;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                min-height: 42px;
                padding: 0 18px;
                border: 1px solid var(--line);
                border-radius: 8px;
                background: var(--card);
                color: var(--ink);
                font: inherit;
                font-size: 0.95rem;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
                transition: border-color 0.15s, background-color 0.15s, color 0.15s;
            }

            .btn:hover { border-color: var(--accent); color: var(--accent); }

            .btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

            .btn-primary {
                border-color: var(--accent);
                background: var(--accent);
                color: #ffffff;
            }

            .btn-primary:hover { background: var(--accent-hover); border-color: var(--accent-hover); color: #ffffff; }

            .btn svg { width: 16px; height: 16px; }

            .btn[hidden] { display: none; }

            .meta {
                margin-top: 40px;
                color: var(--muted);
                font-size: 0.8rem;
            }

            @media (max-width: 420px) {
                .actions { flex-direction: column; }
                .btn { width: 100%; }
            }
        </style>
    </head>
    <body>
        <main>
            <a class="brand" href="{{ PlatformHosts::url(PlatformHosts::root()) }}">
                <picture>
                    <source srcset="/images/logo-with-text-light.png" media="(prefers-color-scheme: dark)">
                    <img src="/images/logo-with-text.png" alt="Miles Global Softwares">
                </picture>
            </a>

            <p class="code" aria-hidden="true">{{ $code }}</p>
            <h1>@yield('title')</h1>
            <p class="message">@yield('message')</p>

            <div class="actions">
                <a class="btn btn-primary" href="{{ $homeUrl }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>
                    {{ $homeLabel }}
                </a>
                <button type="button" class="btn" data-back hidden>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    Go back
                </button>
                @if ($code === '419' || (int) $code >= 500)
                    <a class="btn" href="{{ request()->fullUrl() }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36L21 8M21 3v5h-5"/></svg>
                        Try again
                    </a>
                @endif
                @if ($showSignIn)
                    <a class="btn" href="{{ $signInUrl }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
                        Sign in
                    </a>
                @endif
                @if ($showRegister)
                    <a class="btn" href="{{ $registerUrl }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
                        Create account
                    </a>
                @endif
                @if ($dashboardUrl)
                    <a class="btn" href="{{ $dashboardUrl }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                        Dashboard
                    </a>
                @endif
                @if ($contactUrl)
                    <a class="btn" href="{{ $contactUrl }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Contact support
                    </a>
                @endif
            </div>

            <p class="meta">Error {{ $code }} · {{ $host }}</p>
        </main>

        {{-- Shown only when there is a page to go back to. --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function () {
                var back = document.querySelector('[data-back]');
                if (back && window.history.length > 1) {
                    back.hidden = false;
                    back.addEventListener('click', function () { window.history.back(); });
                }
            })();
        </script>
    </body>
</html>
