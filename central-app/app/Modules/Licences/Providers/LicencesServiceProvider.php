<?php

namespace App\Modules\Licences\Providers;

use App\Modules\Licences\Console\LicenceKeygen;
use Illuminate\Support\ServiceProvider;

class LicencesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([LicenceKeygen::class]);
        }
    }
}
