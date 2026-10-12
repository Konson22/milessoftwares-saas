<?php

namespace Miles\PlatformClient;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;
use Miles\PlatformClient\Http\Middleware\ResolveTenant;
use Miles\PlatformClient\Provisioning\NothingToProvision;
use Miles\PlatformClient\Provisioning\ProvisionsTenants;
use Miles\PlatformClient\Tenancy\CurrentTenant;

final class PlatformClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/platform-client.php', 'platform-client');

        $this->app->singleton(PlatformConfig::class);

        // Per request (and per queued job): never leak between them.
        $this->app->scoped(CurrentTenant::class);
        $this->app->scoped(CurrentAccess::class);

        $this->app->bindIf(ProvisionsTenants::class, NothingToProvision::class);
    }

    public function boot(Router $router, Gate $gate): void
    {
        $this->publishes([__DIR__.'/../config/platform-client.php' => config_path('platform-client.php')], 'platform-client-config');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $router->aliasMiddleware('platform.tenant', ResolveTenant::class);
        $router->aliasMiddleware('platform.access', RequirePlatformAccess::class);
        // Everything a product page needs, in order.
        $router->middlewareGroup('platform', ['platform.tenant', 'platform.access']);

        if (config('platform-client.routes', true) && ! $this->app->routesAreCached() && $this->app->make(PlatformConfig::class)->configured()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/platform-client.php');
        }

        // Catalog permissions ("pharmacy.dispense") work with can(), @can and
        // policies. Abilities without a dot are left to the product's own
        // gates and policies.
        $gate->before(function ($user, string $ability): ?bool {
            if (! str_contains($ability, '.')) {
                return null;
            }

            $access = $this->app->make(CurrentAccess::class)->get();

            return $access !== null && $access->has($ability) ? true : null;
        });
    }
}
