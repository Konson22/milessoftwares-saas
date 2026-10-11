<?php

namespace App\Modules\Memberships\Providers;

use App\Modules\Memberships\Console\ExpireInvitations;
use Illuminate\Support\ServiceProvider;

class MembershipsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireInvitations::class]);
        }
    }
}
