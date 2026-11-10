<?php

namespace App\Providers;

use App\Access\CurrentAccess;
use App\Audit\Audit;
use App\Http\Middleware\ConfineFunderContacts;
use App\Http\Middleware\EnterWorkspace;
use App\Models\User;
use App\Platform\SetUpWorkspace;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Provisioning\ProvisionsTenants;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request (and per queued job): never leak between them.
        $this->app->scoped(CurrentOrganization::class);
        $this->app->scoped(CurrentAccess::class);

        // What the platform's provisioning call sets up for a new workspace.
        $this->app->bind(ProvisionsTenants::class, SetUpWorkspace::class);
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Built assets live under the product's path (vite.config.ts).
        $platform = $this->app->make(PlatformConfig::class);
        if ($platform->configured()) {
            Vite::useBuildDirectory($platform->path().'/build');
        }

        // After the platform has found the organization and checked the
        // member (miles/platform-client puts those before route-model
        // binding), and still before binding: {project} and the rest are
        // looked up inside the organization. Once every provider has booted,
        // so the platform's middleware is in the list to follow.
        $this->app->booted(function () {
            // The contract: the kernel serving requests (the class itself would be a new one).
            $kernel = $this->app->make(Kernel::class);
            $kernel->addToMiddlewarePriorityAfter(RequirePlatformAccess::class, EnterWorkspace::class);
            $kernel->addToMiddlewarePriorityAfter(EnterWorkspace::class, ConfineFunderContacts::class);
        });

        // Sign-ins (through the platform) and sign-outs go to the audit log.
        Event::listen(function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
                Audit::record('auth.login', $event->user, actor: $event->user);
            }
        });
        Event::listen(function (Logout $event) {
            if ($event->user instanceof User) {
                Audit::record('auth.logout', $event->user, actor: $event->user);
            }
        });
    }
}
