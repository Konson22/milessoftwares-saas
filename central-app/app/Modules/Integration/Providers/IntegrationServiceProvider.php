<?php

namespace App\Modules\Integration\Providers;

use App\Modules\Integration\Console\RetryWebhooks;
use App\Modules\Integration\Console\SetUpSampleApp;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RetryWebhooks::class, SetUpSampleApp::class]);
        }
    }
}
