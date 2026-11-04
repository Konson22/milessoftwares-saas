<?php

namespace App\Modules\Applications\Providers;

use App\Modules\Applications\Console\ImportCatalogs;
use App\Modules\Applications\Console\MoveAssignmentsToCatalog;
use Illuminate\Support\ServiceProvider;

class ApplicationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ImportCatalogs::class, MoveAssignmentsToCatalog::class]);
        }
    }
}
