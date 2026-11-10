<?php

use App\Actions\Spending;
use App\Actions\StaffAdvances;
use App\Enums\ExpenseStatus;
use App\Models\AdvanceRetirement;
use App\Models\AdvanceSettlement;
use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\Project;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Staff advances. The books are Finance's: what an advance does to them is
| worked out here from the events Finance was sent (the platform outbox).
*/

const AD_STAFF = ['finance.advances.request'];
const AD_APPROVER = ['finance.advances.view', 'finance.advances.approve'];
const AD_CASHIER = ['finance.advances.view', 'finance.advances.disburse'];

beforeEach(function () {
    Storage::fake('local');
    $this->organization('acme');
    $this->staff = $this->actingAsMember('acme', AD_STAFF);
    $this->approver = $this->member('acme', AD_APPROVER);
    $this->cashier = $this->member('acme', AD_CASHIER);
});

function adUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, 'advances'.($path === '' ? '' : "/{$path}"));
}

function adPolicy(int $max = 50000, int $days = 14): void
{
    test()->organization('acme')->forceFill(['advance_max_minor' => $max, 'advance_retire_days' => $days])->save();
}

/**
 * Debit less credit on one side of the books, as Finance posts the events it
 * was sent: advances (an asset), money (bank, cash or mobile money),
 * expenses, or written_off.
 */
function adLedger(string $side): int
{
    $totals = ['advances' => 0, 'money' => 0, 'expenses' => 0, 'written_off' => 0];
    foreach (test()->financeEvents() as $event) {
        $p = $event['payload'];
        [$debit, $credit] = match ($event['type']) {
            'finance.staff_advance.issued' => ['advances', 'money'],
            'finance.project_expense.paid' => ['expenses', $p['method'] === 'staff_advance' ? 'advances' : 'money'],
            'finance.staff_advance.settled' => [$p['kind'] === 'returned' ? 'money' : 'written_off', 'advances'],
            default => throw new RuntimeException("Not an advance event: {$event['type']}"),
        };
        $totals[$debit] += $p['amount_minor'];
        $totals[$credit] -= $p['amount_minor'];
    }

    return $totals[$side];
}

function adAsk(string $amount = '100', ?User $as = null): StaffAdvance
{
    test()->actingAs($as ?? test()->staff);
    test()->post(adUrl(), ['amount' => $amount, 'purpose' => 'Field trip'])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => StaffAdvance::query()->orderByDesc('number')->first());
}

function adApproved(string $amount = '100', ?User $as = null): StaffAdvance
{
    $advance = adAsk($amount, $as);
    test()->actingAs(test()->approver);
    test()->post(adUrl("{$advance->id}/approve"))->assertSessionHasNoErrors();

    return $advance;
}

function adPaid(string $amount = '100', ?User $as = null, ?string $on = null): StaffAdvance
{
    $advance = adApproved($amount, $as);
    test()->actingAs(test()->cashier);
    test()->post(adUrl("{$advance->id}/disburse"), ['payment_method' => 'bank_transfer', 'on' => $on ?? now()->toDateString(), 'reference' => 'TX1'])->assertSessionHasNoErrors();

    return $advance;
}

/**
 * @param  array<string, mixed>  $details
 */
function adLineData(array $details = []): array
{
    return [
        'spent_on' => now()->toDateString(),
        'amount' => '30',
        'description' => 'Fuel',
        'payee' => 'Station',
        'category' => 'other',
        'receipt' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ...$details,
    ];
}

/**
 * @param  array<string, mixed>  $details
 */
function adSubmit(StaffAdvance $advance, array $details = []): AdvanceRetirement
{
    test()->actingAs(test()->staff);
    test()->post(adUrl("{$advance->id}/lines"), adLineData($details))->assertSessionHasNoErrors();
    test()->post(adUrl("{$advance->id}/retirements"))->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => AdvanceRetirement::query()->where('advance_id', $advance->id)->where('status', AdvanceRetirement::SUBMITTED)->sole());
}

it('advances nothing until the organization sets its policy', function () {
    $this->post(adUrl(), ['amount' => '100', 'purpose' => 'Trip'])->assertSessionHasErrors('amount');

    // The limit alone is not enough, nor the days alone.
    $this->actingAsMember('acme', ['organization.manage']);
    $settings = $this->organizationUrl('acme', 'admin/settings/finance-limits');
    $this->put($settings, ['advance_max' => '500'])->assertSessionHasNoErrors();
    $this->actingAs($this->staff);
    $this->post(adUrl(), ['amount' => '100', 'purpose' => 'Trip'])->assertSessionHasErrors('amount');

    $this->actingAsMember('acme', ['organization.manage']);
    $this->put($settings, ['advance_max' => '500', 'advance_retire_days' => '14'])->assertSessionHasNoErrors();
    expect(AuditEvent::query()->where('action', 'advance.policy_changed')->count())->toBe(2);

    $this->actingAs($this->staff);
    $this->post(adUrl(), ['amount' => '100', 'purpose' => 'Trip'])->assertSessionHasNoErrors();
});

it('holds a request to the limit, and one open advance at a time', function () {
    adPolicy(50000);

    $this->post(adUrl(), ['amount' => '500.01', 'purpose' => 'Trip'])->assertSessionHasErrors('amount');
    $this->post(adUrl(), ['amount' => '0', 'purpose' => 'Trip'])->assertSessionHasErrors('amount');

    $advance = adAsk('500');
    expect($advance->reference())->toBe('ADV-1');
    $this->post(adUrl(), ['amount' => '1', 'purpose' => 'Another'])->assertSessionHasErrors('amount');
    $this->get(adUrl())->assertInertia(fn (Assert $page) => $page->where('canRequest', false)->has('mine', 1));

    // Someone else is not held back, and a cancelled one no longer counts.
    $other = $this->member('acme', AD_STAFF);
    adAsk('1', $other);
    $this->actingAs($this->staff);
    $this->post(adUrl("{$advance->id}/cancel"))->assertSessionHasNoErrors();
    $this->post(adUrl(), ['amount' => '1', 'purpose' => 'Another'])->assertSessionHasNoErrors();
});

it('tells Finance only when paid out, and then as an asset and not an expense', function () {
    adPolicy();
    $advance = adApproved();
    expect($this->financeEvents())->toBe([])
        ->and(UserNotification::query()->where('user_id', $this->cashier->id)->where('kind', UserNotification::APPROVAL)->count())->toBe(1);

    $this->actingAs($this->cashier);
    $this->post(adUrl("{$advance->id}/disburse"), ['payment_method' => 'pigeon', 'on' => '2026-11-01'])->assertSessionHasErrors('payment_method');
    $this->post(adUrl("{$advance->id}/disburse"), ['payment_method' => 'mobile_money', 'on' => '2026-11-01', 'reference' => 'TX1'])->assertSessionHasNoErrors();

    $fresh = $this->asTenant('acme', fn () => $advance->fresh());
    expect($fresh->status)->toBe(StaffAdvance::DISBURSED)
        ->and($fresh->due_on->toDateString())->toBe('2026-11-15')
        ->and($fresh->payment_method)->toBe('mobile_money')
        ->and(adLedger('advances'))->toBe(10000)
        ->and(adLedger('money'))->toBe(-10000)
        ->and(adLedger('expenses'))->toBe(0);

    $issued = $this->financeEvents('acme', 'finance.staff_advance.issued');
    expect($issued)->toHaveCount(1)
        ->and($issued[0]['payload'])->toBe([
            'advance_reference' => StaffAdvances::reference($fresh),
            'issued_on' => '2026-11-01',
            'currency' => $fresh->currency,
            'amount_minor' => 10000,
            'method' => 'mobile_money',
            'held_by' => $this->staff->name,
            'purpose' => 'Field trip',
        ]);
});

it('turns receipts into expenses only when someone else approves them', function () {
    adPolicy();
    $advance = adPaid();

    $this->actingAs($this->staff);
    $retirement = adSubmit($advance);
    expect(adLedger('expenses'))->toBe(0);

    $this->actingAs($this->approver);
    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/approve"))->assertSessionHasNoErrors();

    $fresh = $this->asTenant('acme', fn () => $advance->fresh());
    $paid = $this->financeEvents('acme', 'finance.project_expense.paid');
    expect($paid)->toHaveCount(1)
        ->and($paid[0]['payload'])->toMatchArray(['method' => 'staff_advance', 'category' => 'other', 'amount_minor' => 3000, 'supplier' => 'Station', 'payment_reference' => StaffAdvances::reference($fresh), 'project' => null])
        ->and(adLedger('expenses'))->toBe(3000)
        ->and(adLedger('advances'))->toBe(7000)
        ->and($fresh->status)->toBe(StaffAdvance::DISBURSED)
        ->and($this->asTenant('acme', fn () => app(StaffAdvances::class)->outstanding($fresh)))->toBe(7000);

    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/approve"))->assertSessionHasErrors('retirement');
});

it('settles once receipts and returned cash cover it, and then allows a new one', function () {
    adPolicy();
    $advance = adPaid();
    $retirement = adSubmit($advance, ['amount' => '60']);
    $this->actingAs($this->approver);
    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/approve"))->assertSessionHasNoErrors();

    $this->actingAs($this->cashier);
    $this->post(adUrl("{$advance->id}/returns"), ['amount' => '40.01', 'payment_method' => 'cash', 'on' => now()->toDateString()])->assertSessionHasErrors('amount');
    $this->post(adUrl("{$advance->id}/returns"), ['amount' => '40', 'payment_method' => 'cash', 'on' => now()->toDateString()])->assertSessionHasNoErrors();

    $settled = $this->financeEvents('acme', 'finance.staff_advance.settled');
    expect($this->asTenant('acme', fn () => $advance->fresh()->status))->toBe(StaffAdvance::SETTLED)
        ->and($settled)->toHaveCount(1)
        ->and($settled[0]['payload'])->toMatchArray(['kind' => 'returned', 'method' => 'cash', 'amount_minor' => 4000])
        ->and(adLedger('advances'))->toBe(0)
        ->and(adLedger('money'))->toBe(-6000)
        ->and(adLedger('expenses'))->toBe(6000);

    adAsk('10');
});

it('keeps receipts within what is left', function () {
    adPolicy();
    $advance = adPaid('50');

    $this->actingAs($this->staff);
    $this->post(adUrl("{$advance->id}/lines"), adLineData(['amount' => '30']))->assertSessionHasNoErrors();
    $this->post(adUrl("{$advance->id}/lines"), adLineData(['amount' => '20.01']))->assertSessionHasErrors('amount');
    $this->post(adUrl("{$advance->id}/lines"), adLineData(['receipt' => null]))->assertSessionHasErrors('receipt');
    $this->post(adUrl("{$advance->id}/lines"), adLineData(['category' => '']))->assertSessionHasErrors('category');
    $this->post(adUrl("{$advance->id}/lines"), adLineData(['category' => 'labour']))->assertSessionHasErrors('category');
    $this->post(adUrl("{$advance->id}/lines"), adLineData(['spent_on' => now()->addDay()->toDateString()]))->assertSessionHasErrors('spent_on');
});

it('never lets receipts account for more than is still owed', function () {
    adPolicy();
    $advance = adPaid();

    // Receipts sit in a draft while cash comes back.
    $this->actingAs($this->staff);
    $this->post(adUrl("{$advance->id}/lines"), adLineData(['amount' => '60']))->assertSessionHasNoErrors();
    $this->actingAs($this->cashier);
    $this->post(adUrl("{$advance->id}/returns"), ['amount' => '50', 'payment_method' => 'bank_transfer', 'on' => now()->toDateString()])->assertSessionHasNoErrors();

    $this->actingAs($this->staff);
    $this->post(adUrl("{$advance->id}/retirements"))->assertSessionHasErrors('retirement');
    $this->post(adUrl("{$advance->id}/lines"), adLineData(['amount' => '1']))->assertSessionHasErrors('amount');

    $line = $this->asTenant('acme', fn () => AdvanceRetirement::query()->sole()->lines()->sole());
    $this->delete(adUrl("{$advance->id}/lines/{$line->id}"))->assertSessionHasNoErrors();
    $retirement = adSubmit($advance, ['amount' => '50']);

    $this->actingAs($this->approver);
    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/approve"))->assertSessionHasNoErrors();
    expect(adLedger('advances'))->toBe(0)
        ->and($this->asTenant('acme', fn () => $advance->fresh()->status))->toBe(StaffAdvance::SETTLED);
});

it('stays off when petty cash and advances are off', function () {
    $this->actingAsMember('acme', owner: true);
    $this->put($this->organizationUrl('acme', 'admin/settings/modules/accounting'), ['enabled' => false])->assertSessionHasNoErrors();

    $this->get(adUrl())->assertForbidden();
});

it('returns receipts to be corrected, with a reason', function () {
    adPolicy();
    $advance = adPaid();
    $retirement = adSubmit($advance);

    // Waiting receipts can't change, and more can't be added.
    $line = $this->asTenant('acme', fn () => $retirement->lines()->sole());
    $this->delete(adUrl("{$advance->id}/lines/{$line->id}"))->assertSessionHasErrors('retirement');
    $this->post(adUrl("{$advance->id}/lines"), adLineData())->assertSessionHasErrors('amount');

    $this->actingAs($this->approver);
    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/return"), ['note' => ''])->assertSessionHasErrors('note');
    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/return"), ['note' => 'Unreadable'])->assertSessionHasNoErrors();

    $fresh = $this->asTenant('acme', fn () => $retirement->fresh());
    expect($fresh->status)->toBe(AdvanceRetirement::DRAFT)
        ->and($fresh->returned_reason)->toBe('Unreadable')
        ->and(adLedger('expenses'))->toBe(0);

    $this->actingAs($this->staff);
    $this->delete(adUrl("{$advance->id}/lines/{$line->id}"))->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($line->receipt_path);
    $this->post(adUrl("{$advance->id}/retirements"))->assertSessionHasErrors('retirement');
});

it('never lets whoever holds an advance decide or handle its money, even an owner', function () {
    adPolicy();
    $owner = $this->member('acme', owner: true);

    $advance = adAsk('100', $owner);
    $this->post(adUrl("{$advance->id}/approve"))->assertSessionHasErrors('advance');
    $this->post(adUrl("{$advance->id}/reject"), ['note' => 'No'])->assertSessionHasErrors('advance');

    $this->actingAs($this->approver);
    $this->post(adUrl("{$advance->id}/approve"))->assertSessionHasNoErrors();

    $this->actingAs($owner);
    $this->post(adUrl("{$advance->id}/disburse"), ['payment_method' => 'cash', 'on' => now()->toDateString()])->assertSessionHasErrors('advance');

    $this->actingAs($this->cashier);
    $this->post(adUrl("{$advance->id}/disburse"), ['payment_method' => 'cash', 'on' => now()->toDateString()])->assertSessionHasNoErrors();

    $this->actingAs($owner);
    $this->post(adUrl("{$advance->id}/returns"), ['amount' => '1', 'payment_method' => 'cash', 'on' => now()->toDateString()])->assertSessionHasErrors('advance');
    $this->post(adUrl("{$advance->id}/write-offs"), ['write_off_amount' => '1', 'reason' => 'Mine'])->assertSessionHasErrors('advance');

    $this->post(adUrl("{$advance->id}/lines"), adLineData())->assertSessionHasNoErrors();
    $this->post(adUrl("{$advance->id}/retirements"))->assertSessionHasNoErrors();
    $retirement = $this->asTenant('acme', fn () => AdvanceRetirement::query()->sole());
    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/approve"))->assertSessionHasErrors('advance');
    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/return"), ['note' => 'No'])->assertSessionHasErrors('advance');

    expect(adLedger('expenses'))->toBe(0);
});

it('lets only the holder account for an advance', function () {
    adPolicy();
    $advance = adPaid();

    $this->actingAsMember('acme', AD_STAFF);
    $this->post(adUrl("{$advance->id}/lines"), adLineData())->assertSessionHasErrors('advance');
    $this->post(adUrl("{$advance->id}/retirements"))->assertSessionHasErrors('advance');
});

it('writes off only with a second person and a reason', function () {
    adPolicy();
    $advance = adPaid();

    $this->actingAs($this->cashier);
    $this->post(adUrl("{$advance->id}/write-offs"), ['write_off_amount' => '100', 'reason' => ''])->assertSessionHasErrors('reason');
    $this->post(adUrl("{$advance->id}/write-offs"), ['write_off_amount' => '100.01', 'reason' => 'Left'])->assertSessionHasErrors('amount');
    $this->post(adUrl("{$advance->id}/write-offs"), ['write_off_amount' => '100', 'reason' => 'Left the organization'])->assertSessionHasNoErrors();
    $this->post(adUrl("{$advance->id}/write-offs"), ['write_off_amount' => '1', 'reason' => 'Again'])->assertSessionHasErrors('amount');

    // Nothing moves until it is decided, and what waits can't be spent twice.
    expect(adLedger('written_off'))->toBe(0);
    $this->post(adUrl("{$advance->id}/returns"), ['amount' => '1', 'payment_method' => 'cash', 'on' => now()->toDateString()])->assertSessionHasErrors('amount');
    $settlement = $this->asTenant('acme', fn () => AdvanceSettlement::query()->sole());

    // Asking and approving are different permissions, and different people.
    $this->post(adUrl("{$advance->id}/settlements/{$settlement->id}/approve"))->assertForbidden();
    $both = $this->member('acme', [...AD_CASHIER, 'finance.advances.approve'], user: $this->cashier);
    $this->actingAs($both);
    $this->post(adUrl("{$advance->id}/settlements/{$settlement->id}/approve"))->assertSessionHasErrors('settlement');

    $this->actingAs($this->approver);
    $this->post(adUrl("{$advance->id}/settlements/{$settlement->id}/approve"))->assertSessionHasNoErrors();

    expect(adLedger('written_off'))->toBe(10000)
        ->and(adLedger('advances'))->toBe(0)
        ->and($this->asTenant('acme', fn () => $advance->fresh()->status))->toBe(StaffAdvance::SETTLED)
        ->and(AuditEvent::query()->where('action', 'advance.written_off')->count())->toBe(1);
});

it('keeps it owed when a write-off is rejected', function () {
    adPolicy();
    $advance = adPaid();
    $this->actingAs($this->cashier);
    $this->post(adUrl("{$advance->id}/write-offs"), ['write_off_amount' => '100', 'reason' => 'Gone'])->assertSessionHasNoErrors();
    $settlement = $this->asTenant('acme', fn () => AdvanceSettlement::query()->sole());

    $this->actingAs($this->approver);
    $this->post(adUrl("{$advance->id}/settlements/{$settlement->id}/reject"), ['note' => ''])->assertSessionHasErrors('note');
    $this->post(adUrl("{$advance->id}/settlements/{$settlement->id}/reject"), ['note' => 'Still reachable'])->assertSessionHasNoErrors();

    expect(adLedger('advances'))->toBe(10000)
        ->and($this->asTenant('acme', fn () => $advance->fresh()->status))->toBe(StaffAdvance::DISBURSED);
});

it('shows what is late, by age', function () {
    adPolicy(50000, 10);
    $today = now()->toDateString();
    adPaid('100', null, $today);
    adPaid('20', $this->member('acme', AD_STAFF), now()->subDays(25)->toDateString());
    adPaid('30', $this->member('acme', AD_STAFF), now()->subDays(60)->toDateString());
    adPaid('40', $this->member('acme', AD_STAFF), now()->subDays(80)->toDateString());
    adPaid('50', $this->member('acme', AD_STAFF), now()->subDays(200)->toDateString());

    $this->actingAs($this->approver);
    $this->get(adUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/finance/advances/index')
        ->where('aging.buckets.0.amountMinor', 10000)
        ->where('aging.buckets.1.amountMinor', 2000)
        ->where('aging.buckets.2.amountMinor', 3000)
        ->where('aging.buckets.3.amountMinor', 4000)
        ->where('aging.buckets.4.amountMinor', 5000)
        ->where('aging.totalMinor', 24000)
        ->where('all.0.daysOverdue', 190)
        ->has('all', 5));

    // One person's history.
    $this->get(adUrl()."?holder={$this->staff->id}")->assertInertia(fn (Assert $page) => $page->has('all', 1));
});

// Projects

function adProject(string $budget = '100'): Project
{
    $was = auth()->user();
    test()->actingAsMember('acme', ['projects.view', 'projects.manage', 'projects.finance.view']);
    test()->post(test()->organizationUrl('acme', 'projects'), ['code' => 'wash', 'name' => 'Wash'])->assertSessionHasNoErrors();
    $project = test()->asTenant('acme', fn () => Project::query()->sole());
    test()->post(test()->organizationUrl('acme', "projects/{$project->id}/budget/lines"), ['category' => 'materials', 'amount' => $budget])->assertSessionHasNoErrors();
    $version = test()->asTenant('acme', fn () => $project->budgetVersions()->sole());
    test()->post(test()->organizationUrl('acme', "projects/{$project->id}/budget/versions/{$version->id}/submit"))->assertSessionHasNoErrors();
    test()->actingAsMember('acme', ['projects.view', 'projects.finance.view', 'finance.budgets.approve']);
    test()->post(test()->organizationUrl('acme', "projects/{$project->id}/budget/versions/{$version->id}/approve"))->assertSessionHasNoErrors();
    /** @var User $was */
    test()->actingAs($was);

    return $project;
}

it('makes a receipt the project\'s spending only when approved', function () {
    adPolicy();
    $project = adProject();
    $advance = adPaid();
    $retirement = adSubmit($advance, ['project_id' => $project->id, 'category' => 'materials']);
    expect($this->asTenant('acme', fn () => Expense::query()->count()))->toBe(0);

    $this->actingAs($this->approver);
    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/approve"))->assertSessionHasNoErrors();

    $this->asTenant('acme', function () use ($project) {
        $expense = Expense::query()->sole();
        expect($expense->project_id)->toBe($project->id)
            ->and($expense->status)->toBe(ExpenseStatus::Paid)
            ->and($expense->documents()->count())->toBe(1);

        expect(fn () => Spending::cancel($expense, 'No', test()->approver))->toThrow(ValidationException::class, 'staff advance');
    });
    expect($this->financeEvents('acme', 'finance.project_expense.paid')[0]['payload'])->toMatchArray(['method' => 'staff_advance', 'category' => 'materials', 'project' => ['code' => 'WASH', 'name' => 'Wash']])
        ->and(adLedger('advances'))->toBe(7000)
        ->and(adLedger('money'))->toBe(-10000);
});

it('refuses to approve receipts the project budget cannot take, all or none', function () {
    adPolicy();
    $project = adProject('20');
    $advance = adPaid();

    $this->actingAs($this->staff);
    $this->post(adUrl("{$advance->id}/lines"), adLineData(['amount' => '10']))->assertSessionHasNoErrors();
    $retirement = adSubmit($advance, ['amount' => '30', 'project_id' => $project->id, 'category' => 'materials']);

    $this->actingAs($this->approver);
    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/approve"))->assertSessionHasErrors('retirement');

    expect($this->asTenant('acme', fn () => $retirement->fresh()->status))->toBe(AdvanceRetirement::SUBMITTED)
        ->and(adLedger('expenses'))->toBe(0)
        ->and(adLedger('advances'))->toBe(10000)
        ->and($this->asTenant('acme', fn () => Expense::query()->count()))->toBe(0);
});

// Who sees what

it('shows an advance to its holder and those who may view advances', function () {
    adPolicy();
    $advance = adPaid();
    $retirement = adSubmit($advance);
    $line = $this->asTenant('acme', fn () => $retirement->lines()->sole());

    $this->actingAs($this->staff);
    $this->get(adUrl($advance->id))->assertOk();
    $this->get(adUrl("{$advance->id}/lines/{$line->id}/receipt"))->assertOk();
    $this->get(adUrl())->assertInertia(fn (Assert $page) => $page->where('aging', null)->has('all', 0));

    $this->actingAsMember('acme', AD_STAFF);
    $this->get(adUrl($advance->id))->assertForbidden();
    $this->get(adUrl("{$advance->id}/lines/{$line->id}/receipt"))->assertForbidden();
    $this->post(adUrl("{$advance->id}/cancel"))->assertForbidden();
    $this->post(adUrl("{$advance->id}/approve"))->assertForbidden();
    $this->post(adUrl("{$advance->id}/disburse"), [])->assertForbidden();

    $this->actingAsMember('acme', []);
    $this->get(adUrl())->assertForbidden();
    $this->post(adUrl(), ['amount' => '1', 'purpose' => 'No'])->assertForbidden();

    $this->actingAs($this->approver);
    $this->get(adUrl($advance->id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/finance/advances/show')
        ->where('retirements.0.canDecide', true));
});

it('finds a receipt only through its own advance', function () {
    adPolicy();
    $advance = adPaid();
    $retirement = adSubmit($advance);
    $line = $this->asTenant('acme', fn () => $retirement->lines()->sole());
    $other = adPaid('5', $this->member('acme', AD_STAFF));

    $this->actingAs($this->approver);
    $this->get(adUrl("{$other->id}/lines/{$line->id}/receipt"))->assertNotFound();
});

it('keeps another organization out', function () {
    adPolicy();
    $advance = adPaid();
    $retirement = adSubmit($advance);

    $this->organization('other');
    $this->actingAsMember('other', array_unique([...AD_STAFF, ...AD_APPROVER, ...AD_CASHIER]));

    $this->get(adUrl('', 'other'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('all', 0)->has('mine', 0));
    $this->get(adUrl($advance->id, 'other'))->assertNotFound();
    $this->post(adUrl("{$advance->id}/approve", 'other'))->assertNotFound();
    $this->post(adUrl("{$advance->id}/retirements/{$retirement->id}/approve", 'other'))->assertNotFound();
});

it('refuses another organization\'s records in the database', function () {
    adPolicy();
    $advance = adPaid();

    $this->organization('other');
    $theirs = $this->asTenant('other', function () {
        $project = new Project(['code' => 'THEIRS', 'name' => 'Theirs', 'currency' => 'USD']);
        $project->forceFill(['status' => 'active', 'budget_minor' => 0, 'created_by' => test()->staff->id])->save();

        return $project;
    });
    $retirement = adSubmit($advance);
    $line = $this->asTenant('acme', fn () => $retirement->lines()->sole());

    expect(fn () => DB::transaction(fn () => DB::table('advance_retirement_lines')->where('id', $line->id)->update(['project_id' => $theirs->id])))
        ->toThrow(QueryException::class, 'advance_retirement_lines_project_id_tenant_fk');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL enforces this');
