<?php

namespace App\Support;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ServeInertiaAsJson;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Miles\PlatformClient\PlatformConfig;

/**
 * The web pages for the desktop app: every route of routes/web.php again
 * under {workspace}/restaurant/api/v1/app/<web path>, named
 * "api.app.<name>", with the same controller and the same permissions,
 * signed in like the rest of the API (the app's access token, the branch
 * from X-Branch-Id) and answered as JSON (ServeInertiaAsJson). A page added
 * to the web is in the app's API with nothing more to do.
 */
final class AppPages
{
    /** Under the product's path. */
    public const PREFIX = 'api/v1/app';

    /** Where a branch page's web path starts: branches/{branch}/... */
    public const BRANCH_PREFIX = 'branches/{branch}';

    /**
     * Web-only: the app chooses its branch itself, and the web's old
     * addresses (before branches/{branch}) only redirect.
     */
    private const SKIP = ['branch.edit', 'branch.update', 'start', 'legacy.branch', 'legacy.overview', 'legacy.staff'];

    /** A web path without its branch: "branches/{branch}/floor" is "floor". */
    public static function withoutBranch(string $uri): string
    {
        if ($uri === self::BRANCH_PREFIX) {
            return '';
        }

        return str_starts_with($uri, self::BRANCH_PREFIX.'/') ? substr($uri, strlen(self::BRANCH_PREFIX) + 1) : $uri;
    }

    /** The web's sign-in and branch, swapped for the API's. */
    private const REPLACED = ['web', 'platform', 'branch'];

    public static function mirror(Router $router, PlatformConfig $platform): void
    {
        $path = trim($platform->path(), '/');

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! self::isPage($route)) {
                continue;
            }

            $middleware = $route->middleware();
            $uri = ltrim(substr($route->uri(), strlen($path)), '/');
            // The app names its branch in X-Branch-Id, not in the path: its
            // pages stay where they always were ("floor", "orders/<id>").
            $uri = self::withoutBranch($uri);
            $action = $route->getAction();
            $action['middleware'] = [
                'api',
                'platform.api',
                'throttle:240,1',
                ...(in_array('branch', $middleware, true) ? ['branch.api'] : []),
                HandleInertiaRequests::class,
                ServeInertiaAsJson::class,
                ...array_values(array_diff($middleware, self::REPLACED)),
            ];
            $action['as'] = 'api.app.'.$route->getName();
            unset($action['prefix']);

            $router->addRoute($route->methods(), trim("{$path}/".self::PREFIX."/{$uri}", '/'), $action)
                ->setWheres($route->wheres);
        }
    }

    private static function isPage(Route $route): bool
    {
        $name = $route->getName();

        return $name !== null
            && in_array('platform', $route->middleware(), true)
            && str_starts_with($route->getActionName(), 'App\\')
            && ! in_array($name, self::SKIP, true)
            && ! str_starts_with($name, 'api.');
    }
}
