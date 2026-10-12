<?php

namespace App\Providers;

use App\Pharmacy\CurrentBranch;
use App\Platform\SetUpWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
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

        // Per request and per job, like the tenant.
        $this->app->scoped(CurrentBranch::class);
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Built assets live under the product's path (vite.config.ts).
        $platform = $this->app->make(PlatformConfig::class);
        if ($platform->configured()) {
            Vite::useBuildDirectory($platform->path().'/build');
        }
    }
}
