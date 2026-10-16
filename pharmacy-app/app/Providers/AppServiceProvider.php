<?php

namespace App\Providers;

use App\Modules\Pharmacy\Listeners\ReturnVoidedSaleToPrescription;
use App\Modules\Sales\Events\SaleVoided;
use App\Platform\SetUpWorkspace;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
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

        // Shared modules announce what happened; the industry module reacts.
        Event::listen(SaleVoided::class, ReturnVoidedSaleToPrescription::class);

        // Built assets live under the product's path (vite.config.ts).
        $platform = $this->app->make(PlatformConfig::class);
        if ($platform->configured()) {
            Vite::useBuildDirectory($platform->path().'/build');
        }
    }
}
