<?php

namespace App\Modules\Entitlements\Providers;

use App\Modules\Entitlements\Console\SyncPlanFeatures;
use Illuminate\Support\ServiceProvider;

class EntitlementsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncPlanFeatures::class]);
        }
    }
}
