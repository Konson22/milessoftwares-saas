<?php

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Console\ExportAuditLog;
use App\Modules\Audit\Console\VerifyAuditChain;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAuditChain::class, ExportAuditLog::class]);
        }
    }
}
