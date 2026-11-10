<?php

namespace App\Providers;

use App\Platform\SetUpWorkspace;
use App\Support\CurrentBranch;
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

        // The branch a request works in (RequireBranch): never carried over.
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
