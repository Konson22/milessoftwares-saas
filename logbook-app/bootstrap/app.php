<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Logbook\StaleRecord;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\Http\Middleware\SecurityHeaders;
use Miles\PlatformClient\PlatformConfig;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The phone app's API (routes/api.php): bearer token, no session.
            Route::middleware('api')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The product shares the workspace's origin: same browser policy.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Signed out: sign in through the platform, on this workspace.
        $middleware->redirectGuestsTo(fn () => route('platform.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A task or exception changed since it was opened (ADR-0026 §2.4): 409 for the phone, a form error on the web.
        $exceptions->render(function (StaleRecord $e, Request $request) {
            if ($request->is(app(PlatformConfig::class)->path().'/api/*') || $request->expectsJson()) {
                return response()->json(['code' => $e->reason, 'message' => $e->getMessage(), 'revision' => $e->revision], 409);
            }

            return back()->withErrors(['stale' => $e->getMessage().' Reload to see it as it is now.']);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is(app(PlatformConfig::class)->path().'/platform/*')
                || $request->is(app(PlatformConfig::class)->path().'/api/*')
                || $request->expectsJson(),
        );
    })->create();
