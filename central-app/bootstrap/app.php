<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Identity\Http\Middleware\EnforceSessionState;
use App\Modules\Integration\Http\AuthenticateApplicationClient;
use App\Sites\Admin\Http\Middleware\AdminIdleTimeout;
use App\Sites\Admin\Http\Middleware\EnsurePlatformStaff;
use App\Sites\Admin\Http\Middleware\RequirePlatformPermission;
use App\Sites\Central\Http\Middleware\EnsureWorkspaceAccess;
use App\Sites\Central\Http\Middleware\ResolveCurrentOrganization;
use App\Sites\Central\Http\Middleware\ResolveTenant;
use App\Support\Hosts\HostType;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // One file per site. Order matters: central.php ends with the
        // {tenant} wildcard host, which must be registered last.
        web: [
            __DIR__.'/../routes/public.php',
            __DIR__.'/../routes/admin.php',
            __DIR__.'/../routes/central.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::domain(PlatformHosts::auth())->group(base_path('routes/oauth.php'));
            Route::domain(PlatformHosts::auth())->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only the root domain and its first-level subdomains are accepted as
        // Host headers (not enforced in local/testing environments).
        $middleware->trustHosts(at: fn () => [(string) config('platform.root_domain')], subdomains: true);

        // Unknown tenants must 404 before auth can redirect to sign-in.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ResolveTenant::class);

        // Guests on app./admin. start an OIDC sign-in on their own host; elsewhere
        // they go straight to the auth host sign-in page.
        $middleware->redirectGuestsTo(fn (Request $request) => match (PlatformHosts::classify($request->getHost())['type']) {
            HostType::App => PlatformHosts::url(PlatformHosts::app(), 'auth/login'),
            HostType::Admin => PlatformHosts::url(PlatformHosts::admin(), 'auth/login'),
            HostType::Workspace => PlatformHosts::url($request->getHost(), 'auth/login'),
            default => route('login'),
        });

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'workspace.access' => EnsureWorkspaceAccess::class,
            'current.organization' => ResolveCurrentOrganization::class,
            'platform.staff' => EnsurePlatformStaff::class,
            'staff.can' => RequirePlatformPermission::class,
            'admin.idle' => AdminIdleTimeout::class,
            'api.client' => AuthenticateApplicationClient::class,
        ]);

        $middleware->append(SecurityHeaders::class);

        $middleware->encryptCookies(except: ['appearance']);

        $middleware->web(append: [
            EnforceSessionState::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
