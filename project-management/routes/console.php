<?php

use App\Actions\Organizations as OrganizationCopies;
use App\Actions\PettyCash;
use App\Actions\Reminders;
use App\Models\Organization;
use App\Models\PettyCashFund;
use App\Support\FinanceSync;
use App\Support\Money;
use App\Support\ParticipationInventory;
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

// What Finance did with the money events sent to it (App\Support\FinanceSync).
// Read-only: it reports, it never resends, reverses or fixes. Exceptions
// (refused, conflicting, undeliverable, or expenses out of step with what
// Finance was told) are listed apart from delays. --notify tells each
// organization's payment recorders, counts only. Exit code 1 when an
// exception or an overdue event needs a person.
Artisan::command('projects:finance-sync {--organization= : one workspace, by slug} {--notify : tell the organizations\' payment recorders}', function (CurrentOrganization $current) {
    $organizationId = null;
    if ($this->option('organization')) {
        $organizationId = Organization::query()->where('slug', $this->option('organization'))->value('id');
        if ($organizationId === null) {
            $this->error('No such workspace.');

            return 1;
        }
    }
    $names = Organization::query()->pluck('slug', 'id');
    $name = fn (string $id) => $names[$id] ?? $id;

    foreach (FinanceSync::summary($organizationId) as $id => $counts) {
        ksort($counts);
        $this->line($name($id).': '.collect($counts)->map(fn (int $count, string $status) => "{$status} {$count}")->implode(', '));
    }

    $findings = collect(FinanceSync::review($organizationId));
    $line = fn (array $finding) => $name($finding['organization_id'])." {$finding['kind']} {$finding['reference']}"
        .($finding['event_type'] ? " ({$finding['event_type']}, {$finding['attempts']} attempt(s))" : '')
        .($finding['answer'] ? " — {$finding['answer']}" : '');
    $exceptions = $findings->whereIn('kind', FinanceSync::EXCEPTIONS);
    $delays = $findings->whereIn('kind', FinanceSync::DELAYS);

    $this->line('Exceptions: '.$exceptions->count());
    $exceptions->each(fn (array $finding) => $this->error('  '.$line($finding)));
    $this->line('Delays: '.$delays->count());
    $delays->each(fn (array $finding) => $this->warn('  '.$line($finding)));

    if ($this->option('notify')) {
        foreach ($findings->groupBy('organization_id') as $id => $ofOrganization) {
            $organization = Organization::query()->find($id);
            if ($organization !== null && $organization->isActive()) {
                $told = $current->run($organization, fn () => FinanceSync::alert($ofOrganization->values()->all()));
                $this->line($name((string) $id).": {$told} told");
            }
        }
    }

    $needsPerson = $exceptions->isNotEmpty() || $delays->contains('kind', 'overdue');
    if (! $needsPerson) {
        $this->info('Nothing needs attention.');
    }

    return $needsPerson ? 1 : 0;
})->purpose('Report money events Finance refused or has not taken, and expenses out of step (read-only)');
Schedule::command('projects:finance-sync --notify')->dailyAt('06:00')->withoutOverlapping();

// ADR-0018 step 1 (App\Support\ParticipationInventory): every way another
// organization is involved in projects today, and what a move to project
// participations would meet. Read-only: it changes no partner record.
Artisan::command('projects:participation-inventory {--organization= : one workspace, by slug} {--json : machine-readable}', function () {
    $organizationId = null;
    if ($this->option('organization')) {
        $organizationId = Organization::query()->where('slug', $this->option('organization'))->value('id');
        if ($organizationId === null) {
            $this->error('No such workspace.');

            return 1;
        }
    }

    $inventory = ParticipationInventory::run($organizationId);
    if ($this->option('json')) {
        $this->line((string) json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
    }

    $this->line('Records');
    foreach ($inventory['counts'] as $what => $count) {
        $this->line("  {$what}: {$count}");
    }
    $this->line('Findings: '.collect(ParticipationInventory::tally($inventory['findings']))->map(fn (int $count, string $kind) => "{$kind} {$count}")->implode(', '));
    foreach ($inventory['findings'] as $finding) {
        $this->line("  {$finding['kind']} [{$finding['organization']}] {$finding['subject']}: {$finding['detail']}");
    }

    return 0;
})->purpose('Inventory donor and partner records for the participation model (read-only)');
