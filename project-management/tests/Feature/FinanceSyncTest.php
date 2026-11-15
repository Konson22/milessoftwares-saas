<?php

use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\FinanceSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
| projects:finance-sync: what Finance did with the money events Projects
| sent, and expenses out of step with them. Read-only: nothing is resent,
| reversed or rewritten; exceptions are told apart from delays; payment
| recorders are told counts, never amounts.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00'));
    Notification::fake();
});

function fsRow(string $slug, string $transaction, string $status, string $created, ?string $error = null, int $attempts = 0): void
{
    DB::table('platform_outbox')->insert([
        'id' => (string) Str::uuid(), 'organization_id' => test()->organization($slug)->id, 'audience' => 'finance',
        'event_type' => str_ends_with($transaction, ':voided') ? 'finance.project_expense.voided' : 'finance.project_expense.paid',
        'schema_version' => 1, 'source_transaction_id' => $transaction, 'payload' => '{}', 'occurred_at' => $created,
        'status' => $status, 'attempts' => $attempts, 'last_error' => $error, 'created_at' => $created, 'updated_at' => $created,
    ]);
}

function fsExpense(string $slug, ExpenseStatus $status): Expense
{
    return test()->asTenant($slug, function () use ($status) {
        $by = User::factory()->create()->id;
        $project = Project::query()->first() ?? Project::query()->create(['code' => 'WASH', 'name' => 'Water', 'status' => Project::ACTIVE, 'currency' => 'USD', 'created_by' => $by]);

        return $project->expenses()->create(['category' => CostCategory::Materials, 'description' => 'Secret pumps', 'supplier' => 'Hidden Ltd', 'amount_minor' => 987_654, 'status' => $status, 'direct' => true, 'spent_on' => '2026-11-02', 'recorded_by' => $by]);
    });
}

it('tells refusals, conflicts, access and give-ups apart from delays', function () {
    fsRow('acme', 'expense:a:paid', 'delivered', '2026-11-15 08:00:00');
    fsRow('acme', 'expense:b:paid', 'failed', '2026-11-15 08:00:00', 'HTTP 422: currency_mismatch', 1);
    fsRow('acme', 'expense:c:paid', 'failed', '2026-11-15 08:00:00', 'HTTP 409: conflict', 1);
    fsRow('acme', 'expense:d:paid', 'failed', '2026-11-15 08:00:00', 'HTTP 403: forbidden', 1);
    fsRow('acme', 'expense:e:paid', 'failed', '2026-11-10 08:00:00', 'Gave up after 8 attempts. HTTP 500: oops', 8);
    fsRow('acme', 'expense:f:paid', 'pending', '2026-11-14 08:00:00', 'HTTP 503', 3); // overdue
    fsRow('acme', 'expense:g:paid', 'pending', '2026-11-16 08:30:00', 'HTTP 503', 1); // retrying
    fsRow('acme', 'expense:h:paid', 'pending', '2026-11-16 08:55:00');                // just sent
    fsRow('acme', 'expense:i:paid', 'duplicate', '2026-11-15 08:00:00');             // already posted: fine

    $kinds = collect(FinanceSync::review())->pluck('kind', 'reference')->all();

    expect($kinds)->toBe([
        'expense:e:paid' => 'gave_up',
        'expense:f:paid' => 'overdue',
        'expense:b:paid' => 'rejected',
        'expense:c:paid' => 'conflict',
        'expense:d:paid' => 'unauthorized',
        'expense:g:paid' => 'retrying',
    ]);

    $this->artisan('projects:finance-sync')
        ->expectsOutputToContain('acme: delivered 1, duplicate 1, failed 4, pending 3')
        ->expectsOutputToContain('Exceptions: 4')
        ->expectsOutputToContain('acme rejected expense:b:paid (finance.project_expense.paid, 1 attempt(s)) — HTTP 422: currency_mismatch')
        ->expectsOutputToContain('Delays: 2')
        ->expectsOutputToContain('acme overdue expense:f:paid')
        ->doesntExpectOutputToContain('expense:h:paid')
        ->assertExitCode(1);

    // Read-only.
    expect(DB::table('platform_outbox')->where('status', 'pending')->count())->toBe(3)
        ->and(DB::table('platform_outbox')->where('status', 'failed')->count())->toBe(4);
});

it('finds expenses out of step with what Finance was told, only where Finance is in use', function () {
    $unsent = fsExpense('acme', ExpenseStatus::Paid);
    $posted = fsExpense('acme', ExpenseStatus::Paid);
    $cancelled = fsExpense('acme', ExpenseStatus::Cancelled);
    $voidOnly = fsExpense('acme', ExpenseStatus::Cancelled);
    fsRow('acme', "expense:{$posted->id}:paid", 'delivered', '2026-11-15 08:00:00');
    fsRow('acme', "expense:{$cancelled->id}:paid", 'delivered', '2026-11-15 08:00:00'); // and no void sent
    fsRow('acme', "expense:{$voidOnly->id}:paid", 'failed', '2026-11-15 08:00:00', 'HTTP 422: closed_period', 1);
    fsRow('acme', "expense:{$voidOnly->id}:voided", 'delivered', '2026-11-15 09:00:00');
    // Globex has no Finance: its events are skipped, and its paid expenses are not "unsent".
    fsExpense('globex', ExpenseStatus::Paid);
    fsRow('globex', 'expense:x:paid', 'skipped', '2026-11-15 08:00:00');

    $found = collect(FinanceSync::review())->map(fn (array $finding) => "{$finding['kind']} {$finding['reference']}")->all();

    expect($found)->toContain("unsent expense:{$unsent->id}")
        ->toContain("unvoided expense:{$cancelled->id}")
        ->toContain("void_unposted expense:{$voidOnly->id}")
        ->toContain("rejected expense:{$voidOnly->id}:paid")
        ->not->toContain("unsent expense:{$posted->id}")
        ->and(collect(FinanceSync::review())->where('organization_id', $this->organization('globex')->id)->all())->toBe([]);
});

it('tells the payment recorders counts once a day, never amounts, and nobody else', function () {
    $recorder = $this->actingAsMember('acme', ['projects.view', 'finance.payments.record', 'finance.commitments.view']);
    $cannotOpen = $this->actingAsMember('acme', ['projects.view', 'finance.payments.record']);
    $viewer = $this->actingAsMember('acme', ['projects.view', 'projects.finance.view']);
    $outsider = $this->actingAsMember('globex', ['projects.view', 'finance.payments.record']);
    $expense = fsExpense('acme', ExpenseStatus::Paid);
    fsRow('acme', 'expense:z:paid', 'delivered', '2026-11-15 08:00:00');
    fsRow('acme', 'expense:b:paid', 'failed', '2026-11-15 08:00:00', 'HTTP 422: currency_mismatch', 1);

    $this->artisan('projects:finance-sync --notify')->expectsOutputToContain('acme: 1 told')->assertExitCode(1);
    $this->artisan('projects:finance-sync --notify')->expectsOutputToContain('acme: 0 told');

    $notes = UserNotification::acrossOrganizations()->get();
    expect($notes)->toHaveCount(1)
        ->and($notes[0]->user_id)->toBe($recorder->id)
        ->and($notes[0]->title)->toBe('Payment records need attention in Finance: 2 exceptions')
        ->and($notes[0]->excerpt)->toContain('1 refused by Finance')->toContain('1 paid but never sent')->toContain('Nothing was resent')
        ->and($notes[0]->excerpt.$notes[0]->title)->not->toContain('9,876')->not->toContain('9876')->not->toContain('Secret')->not->toContain('Hidden');
    expect($notes->pluck('user_id'))->not->toContain($cannotOpen->id)->not->toContain($viewer->id)->not->toContain($outsider->id);
    expect($expense->id)->not->toBeEmpty();
});

it('says so when nothing needs a person, and retries alone do not alarm', function () {
    fsRow('acme', 'expense:a:paid', 'delivered', '2026-11-15 08:00:00');
    fsRow('acme', 'expense:b:paid', 'pending', '2026-11-16 08:00:00', 'HTTP 503', 1);

    $this->artisan('projects:finance-sync --notify')
        ->expectsOutputToContain('Exceptions: 0')
        ->expectsOutputToContain('Delays: 1')
        ->expectsOutputToContain('Nothing needs attention.')
        ->assertExitCode(0);
    expect(UserNotification::acrossOrganizations()->count())->toBe(0);
});
