<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireBranch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Miles\PlatformClient\Http\Middleware\SecurityHeaders;
use Miles\PlatformClient\PlatformConfig;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The product shares the workspace's origin: same browser policy.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias(['branch' => RequireBranch::class]);

        // Signed out: sign in through the platform, on this workspace.
        $middleware->redirectGuestsTo(fn () => route('platform.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is(app(PlatformConfig::class)->path().'/platform/*')
                || $request->expectsJson(),
        );
    })->create();
