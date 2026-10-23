<?php

namespace App\Support;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ServeInertiaAsJson;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Miles\PlatformClient\PlatformConfig;

/**
 * The web pages for the desktop app: every route of routes/web.php again
 * under {workspace}/pharmacy/api/v1/app/<web path>, named "api.app.<name>",
 * and every one of routes/retail.php under {workspace}/retail/api/v1/app,
 * named "retail.api.app.<name>"; with the same controller and the same
 * permissions, signed in like the rest of the API (the app's access token,
 * the branch from X-Branch-Id) and answered as JSON (ServeInertiaAsJson). A
 * page added to the web is in the app's API with nothing more to do.
 */
final class AppPages
{
    /** Under the product's path. */
    public const PREFIX = 'api/v1/app';

    /** Web-only: the app chooses its branch itself. */
    private const SKIP = ['branch.edit', 'branch.update'];

    /** The web's sign-in and branch, swapped for the API's. */
    private const REPLACED = ['web', 'platform', 'branch'];

    public static function mirror(Router $router, PlatformConfig $platform): void
    {
        self::mirrorPages($router, trim($platform->path(), '/'), null);

        if (in_array('retail', $platform->applications(), true)) {
            self::mirrorPages($router, trim($platform->runAs('retail', fn () => $platform->path()), '/'), 'retail');
        }
    }

    /**
     * @param  string|null  $hosted  the hosted application whose pages these are; null: the primary's
     */
    private static function mirrorPages(Router $router, string $path, ?string $hosted): void
    {
        $prefix = $hosted === null ? '' : "{$hosted}.";
        // Chosen before anything else reads the platform (platform.api).
        $select = $hosted === null ? [] : ["platform.app:{$hosted}"];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! self::isPage($route, $prefix)) {
                continue;
            }

            $middleware = $route->middleware();
            $uri = ltrim(substr($route->uri(), strlen($path)), '/');
            $action = $route->getAction();
            $action['middleware'] = [
                'api',
                ...$select,
                'platform.api',
                'throttle:240,1',
                ...(in_array('branch', $middleware, true) ? ['branch.api'] : []),
                HandleInertiaRequests::class,
                ServeInertiaAsJson::class,
                ...array_values(array_diff($middleware, [...self::REPLACED, ...$select])),
            ];
            $action['as'] = $prefix.'api.app.'.substr((string) $route->getName(), strlen($prefix));
            unset($action['prefix']);

            $router->addRoute($route->methods(), trim("{$path}/".self::PREFIX."/{$uri}", '/'), $action)
                ->setWheres($route->wheres);
        }
    }

    /**
     * A web page of the primary ($prefix ''), or of a hosted application
     * ('retail.').
     */
    private static function isPage(Route $route, string $prefix): bool
    {
        $name = $route->getName();
        if ($name === null
            || ! in_array('platform', $route->middleware(), true)
            || ! str_starts_with($route->getActionName(), 'App\\')) {
            return false;
        }

        if ($prefix === '') {
            return ! in_array($name, self::SKIP, true)
                && ! str_starts_with($name, 'retail.')
                && ! str_starts_with($name, 'api.');
        }

        $own = substr($name, strlen($prefix));

        return str_starts_with($name, $prefix)
            && ! in_array($own, self::SKIP, true)
            && ! str_starts_with($own, 'api.');
    }
}
