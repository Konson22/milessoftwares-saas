<?php

namespace App\Modules\Administration\Providers;

use App\Modules\Administration\Console\AddPlatformStaff;
use Illuminate\Support\ServiceProvider;

class AdministrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AddPlatformStaff::class]);
        }
    }
}
