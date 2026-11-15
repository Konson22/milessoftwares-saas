<?php

use App\Actions\Organizations as OrganizationCopies;
use App\Actions\PettyCash;
use App\Actions\Reminders;
use App\Models\Organization;
use App\Models\PettyCashFund;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Database\Seeders\AsaasDemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\PlatformApi;

// Reminders of what is late or coming up, and the daily digest, for every
// active organization. One that fails does not stop the others.
Artisan::command('alerts:remind', function (CurrentOrganization $current) {
    $failed = false;
    foreach (Organization::query()->where('status', Organization::ACTIVE)->get() as $organization) {
        try {
            $result = $current->run($organization, fn () => Reminders::run());
            $this->info("{$organization->slug}: {$result['reminders']} reminders, {$result['digests']} digests");
        } catch (Throwable $e) {
            report($e);
            $failed = true;
            $this->error("{$organization->slug}: {$e->getMessage()}");
        }
    }

    return $failed ? 1 : 0;
})->purpose('Send reminders and the daily digest');
Schedule::command('alerts:remind')->dailyAt('05:00')->withoutOverlapping();

// Demo data (Asaas NGO's projects, grants, spending and team) in a workspace
// entitled to Projects on the platform. Once per workspace.
Artisan::command('projects:demo {slug : the workspace}', function (string $slug, PlatformApi $platform) {
    $organization = OrganizationCopies::mirror($platform->organization($slug));
    $seeder = app(AsaasDemoSeeder::class);
    $seeder->slug = $organization->slug;
    $seeder->run();
    $this->info("Demo data is in {$organization->name}.");
})->purpose('Put the demo projects, grants and team in a workspace');

// Shared modules (Finance): events recorded by the outbox, sent and retried.
Schedule::command('platform:outbox')->everyMinute()->withoutOverlapping();

// Petty cash boxes of organizations with Finance, moved into Finance funds
// (App\Actions\PettyCash::moveToFinance). A box with a top-up waiting or
// overdrawn is left, and listed, until it is sorted out. Safe to run again.
Artisan::command('petty-cash:move-to-finance {--dry-run : list what would move}', function (CurrentOrganization $current, PettyCash $pettyCash) {
    $failed = false;
    foreach (Organization::query()->where('status', Organization::ACTIVE)->get() as $organization) {
        try {
            $current->run($organization, function () use ($organization, $pettyCash, &$failed) {
                $funds = PettyCashFund::query()->where('status', PettyCashFund::ACTIVE)->orderBy('name')->get();
                if ($funds->isEmpty() || ! $pettyCash->keptInFinance()) {
                    return;
                }
                foreach ($funds as $fund) {
                    $held = Money::format($pettyCash->balance($fund), $fund->currency);
                    if ($this->option('dry-run')) {
                        $this->line("{$organization->slug}: {$fund->name} would move, holding {$held}");

                        continue;
                    }
                    try {
                        $pettyCash->moveToFinance($fund, CarbonImmutable::today(), null);
                        $this->info("{$organization->slug}: {$fund->name} moved, holding {$held}");
                    } catch (ValidationException $e) {
                        $failed = true;
                        $this->warn("{$organization->slug}: {$fund->name} left: ".collect($e->errors())->flatten()->first());
                    }
                }
            });
        } catch (Throwable $e) {
            report($e);
            $failed = true;
            $this->error("{$organization->slug}: {$e->getMessage()}");
        }
    }

    return $failed ? 1 : 0;
})->purpose('Move petty cash boxes into Finance for organizations that have it');
