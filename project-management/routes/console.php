<?php

use App\Actions\Organizations as OrganizationCopies;
use App\Actions\PettyCash;
use App\Actions\Reminders;
use App\FinanceIntegration\FinanceMode;
use App\FinanceIntegration\FinanceModes;
use App\FinanceIntegration\ModeChangeRefused;
use App\FinanceIntegration\Readiness;
use App\FinanceIntegration\Reconciliation;
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

// An organization's Finance mode (Phase 3B): shown with its checks, or
// switched by a person, with a reason, when no check blocks. Never run by
// the scheduler: nothing switches a mode on its own.
Artisan::command('projects:finance-mode {organization : the workspace slug} {mode? : legacy, reconciliation_required, ready, integrated or suspended} {--reason= : why (required to switch)} {--by= : who is switching (defaults to the OS user)}', function (Readiness $readiness, FinanceModes $modes) {
    $organization = Organization::query()->where('slug', $this->argument('organization'))->first();
    if ($organization === null) {
        $this->error('No such workspace.');

        return 1;
    }
    $from = FinanceMode::of($organization);
    $target = $this->argument('mode');
    $to = is_string($target) ? FinanceMode::tryFrom($target) : null;
    if ($target !== null && $to === null) {
        $this->error('Unknown mode. One of: '.implode(', ', array_map(fn (FinanceMode $m) => $m->value, FinanceMode::cases())).'.');

        return 1;
    }

    $this->line("{$organization->slug}: {$from->label()}");
    $this->line('Can switch to: '.(implode(', ', array_map(fn (FinanceMode $m) => $m->value, $from->next())) ?: 'nothing'));
    $checkFor = $to ?? ($from->next()[0] ?? $from);
    $this->line("Checks for {$checkFor->value}:");
    foreach ($readiness->check($organization, $checkFor) as $check) {
        $mark = $check['passed'] ? 'ok  ' : ($check['blocking'] ? 'STOP' : 'note');
        $this->line("  [{$mark}] {$check['label']}: {$check['count']}".($check['passed'] ? '' : " ({$check['detail']})"));
    }

    if ($to === null) {
        return 0;
    }

    try {
        $reason = $this->option('reason');
        $by = $this->option('by');
        $change = $modes->change($organization, $to, is_string($reason) ? $reason : '', is_string($by) && $by !== '' ? $by : get_current_user());
    } catch (ModeChangeRefused $e) {
        $this->error('Not switched: '.$e->getMessage());

        return 1;
    }
    $this->info("Switched {$change->from_mode} -> {$change->to_mode}.");

    return 0;
})->purpose('Show or switch an organization\'s Finance mode, after readiness checks');

// Requests handed to Finance against what Finance reported, and refused
// outcomes. Read-only: nothing is resent, reversed or corrected.
Artisan::command('projects:finance-reconcile {--organization= : one workspace, by slug} {--json : machine-readable}', function (Reconciliation $reconciliation) {
    $organizationId = null;
    if ($this->option('organization')) {
        $organizationId = Organization::query()->where('slug', $this->option('organization'))->value('id');
        if ($organizationId === null) {
            $this->error('No such workspace.');

            return 1;
        }
    }

    $result = $reconciliation->run($organizationId);
    if ($this->option('json')) {
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $result['findings'] === [] ? 0 : 1;
    }
    if ($result['findings'] === []) {
        $this->info('Nothing to reconcile.');

        return 0;
    }
    $this->line('Findings: '.collect($result['counts'])->map(fn (int $n, string $kind) => "{$kind} {$n}")->implode(', '));
    foreach ($result['findings'] as $finding) {
        $this->line("  {$finding['kind']} {$finding['reference']}: {$finding['detail']}");
    }

    return 1;
})->purpose('Reconcile requests handed to Finance with Finance\'s outcomes (read-only)');
