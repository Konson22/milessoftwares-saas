<?php

namespace App\Providers;

use App\Platform\SetUpWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Provisioning\ProvisionsTenants;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // What the platform's provisioning call sets up for a new workspace.
        $this->app->bind(ProvisionsTenants::class, SetUpWorkspace::class);
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // The phone app syncs in bursts (entries, then their files one by one).
        RateLimiter::for('logbook-api', fn (Request $request) => Limit::perMinute(300)->by('logbook-api|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Built assets live under the product's path (vite.config.ts).
        $platform = $this->app->make(PlatformConfig::class);
        if ($platform->configured()) {
            Vite::useBuildDirectory($platform->path().'/build');
        }
    }
}
