<?php

namespace App\Modules\Products\Providers;

use App\Modules\Products\Console\SyncProducts;
use Illuminate\Support\ServiceProvider;

class ProductsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncProducts::class]);
        }
    }
}
