<?php

namespace App\Providers;

use App\Finance\ProjectSpending;
use App\Models\Journal;
use App\Platform\SetUpWorkspace;
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
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // A reversed journal that paid a Projects request: Projects is told (ProjectSpending).
        Journal::updated(function (Journal $journal): void {
            if ($journal->wasChanged('reversed_by_id') && $journal->reversed_by_id !== null) {
                app(ProjectSpending::class)->journalReversed($journal);
            }
        });

        // Built assets live under the product's path (vite.config.ts).
        $platform = $this->app->make(PlatformConfig::class);
        if ($platform->configured()) {
            Vite::useBuildDirectory($platform->path().'/build');
        }
    }
}
