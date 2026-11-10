<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireApiBranch;
use App\Http\Middleware\RequireBranch;
use App\Http\Middleware\RequireBranchPermission;
use App\Support\AppPages;
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
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The web pages again for the mobile app, once web.php is loaded. Not
        // while Wayfinder generates the frontend's routes: each controller
        // would have two routes, and the web never calls the app's copies.
        then: function () {
            $generating = app()->runningInConsole() && in_array('wayfinder:generate', $_SERVER['argv'] ?? [], true);
            if (app(PlatformConfig::class)->configured() && ! $generating) {
                AppPages::mirror(app('router'), app(PlatformConfig::class));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'branch' => RequireBranch::class,
            'branch.can' => RequireBranchPermission::class,
            'branch.api' => RequireApiBranch::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('platform.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is(app(PlatformConfig::class)->path().'/platform/*')
                || $request->is(app(PlatformConfig::class)->path().'/api/*')
                || $request->expectsJson(),
        );
    })->create();
