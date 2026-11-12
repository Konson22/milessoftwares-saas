<?php

namespace App\Providers;

use App\Support\Inertia\SitePageFinder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: reset between requests and queued jobs.
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Workspaces one organization creates for others: 30 an hour per
        // sponsor (creates and claim emails each), not per calling server,
        // which every donor of an application shares.
        foreach (['sponsored-create', 'sponsored-resend'] as $name) {
            RateLimiter::for($name, fn (Request $request) => Limit::perHour(30)->by($name.':'.$request->string('sponsor_organization_id')));
        }

        // Pages live per site (resources/js/sites/{site}/pages). Bound in boot()
        // so it replaces the binding the Inertia provider registers.
        $this->app->bind('inertia.view-finder', fn ($app) => new SitePageFinder($app['files'], []));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
