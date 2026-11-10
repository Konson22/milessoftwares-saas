<?php

use App\Actions\PettyCash;
use App\Actions\Spending;
use App\Enums\ExpenseStatus;
use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\PettyCashFund;
use App\Models\PettyCashPayment;
use App\Models\PettyCashTopUp;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\FinanceLink;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Petty cash: the box's balance is worked out here, and Finance (which keeps
| the books) is told of every movement through the platform outbox. These
| organizations do not have Finance; one that does keeps its petty cash
| there, and its boxes here are moved into Finance funds (at the end).
*/

const PC_VIEW = ['finance.pettycash.view'];
const PC_CUSTODIAN = ['finance.pettycash.view', 'finance.pettycash.record'];
const PC_MANAGER = ['finance.pettycash.view', 'finance.pettycash.manage'];
const PC_APPROVER = ['finance.pettycash.view', 'finance.pettycash.approve'];

beforeEach(function () {
    Storage::fake('local');
    $this->platform()->notEntitledTo = ['finance'];
    $this->organization('acme');
    $this->custodian = $this->member('acme', PC_CUSTODIAN);
    $this->manager = $this->actingAsMember('acme', PC_MANAGER);
});

function pcUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, 'petty-cash'.($path === '' ? '' : "/{$path}"));
}

function pcLimit(int $minor): void
{
    test()->organization('acme');
    test()->asTenant('acme', fn () => test()->organization('acme')->forceFill(['petty_cash_max_payment_minor' => $minor])->save());
}

function pcFund(int $float = 100): PettyCashFund
{
    test()->actingAs(test()->manager);
    test()->post(pcUrl(), [
        'name' => 'Juba office',
        'custodian_id' => test()->custodian->id,
        'float' => (string) $float,
        'on' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => PettyCashFund::query()->sole());
}

function pcBalance(PettyCashFund $fund): int
{
    return test()->asTenant('acme', fn () => app(PettyCash::class)->balance($fund->fresh()));
}

/**
 * @param  array<string, mixed>  $details
 */
function pcPayData(array $details = []): array
{
    return [
        'paid_on' => now()->toDateString(),
        'amount' => '10',
        'description' => 'Printer paper',
        'payee' => 'Stationers',
        'category' => 'materials',
        'receipt' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ...$details,
    ];
}

/**
 * @param  array<string, mixed>  $details
 */
function pcPay(PettyCashFund $fund, array $details = []): PettyCashPayment
{
    test()->actingAs(test()->custodian);
    test()->post(pcUrl("{$fund->id}/payments"), pcPayData($details))->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => PettyCashPayment::query()->orderByDesc('created_at')->orderByDesc('id')->first());
}

function pcAskTopUp(PettyCashFund $fund): PettyCashTopUp
{
    test()->actingAs(test()->custodian);
    test()->post(pcUrl("{$fund->id}/top-ups"))->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => PettyCashTopUp::query()->where('status', PettyCashTopUp::SUBMITTED)->sole());
}

function pcApproveData(): array
{
    return ['on' => now()->toDateString()];
}

/**
 * The payloads of what Finance was sent, of one type.
 *
 * @return list<array<string, mixed>>
 */
function pcSent(string $type): array
{
    return array_column(test()->financeEvents('acme', $type), 'payload');
}

it('sets up a box, telling Finance the cash came from the bank', function () {
    $fund = pcFund();

    expect(pcBalance($fund))->toBe(10000)
        ->and(AuditEvent::query()->where('action', 'petty_cash.fund_created')->count())->toBe(1)
        ->and(pcSent('finance.cash.moved'))->toBe([[
            'reference' => "petty-cash:{$fund->id}:setup",
            'currency' => $fund->currency,
            'moved_on' => now()->toDateString(),
            'direction' => 'in',
            'counterpart' => 'bank',
            'amount_minor' => 10000,
            'description' => 'Petty cash Juba office: set up',
            'moved_by' => $this->manager->name,
        ]]);

    $this->get(pcUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/finance/petty-cash/index')
        ->where('funds.0.balanceMinor', 10000)
        ->where('limitMinor', null));
});

it('holds a box only with someone who may pay from it', function () {
    $this->post(pcUrl(), [
        'name' => 'Box',
        'custodian_id' => $this->manager->id,
        'float' => '100',
        'on' => now()->toDateString(),
    ])->assertSessionHasErrors('custodian_id');

    expect($this->financeEvents())->toBe([]);
});

it('pays nothing until the organization sets its limit', function () {
    $fund = pcFund();

    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData())->assertSessionHasErrors('amount');
    expect($this->asTenant('acme', fn () => PettyCashPayment::query()->count()))->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty("organizations/{$fund->organization_id}/petty-cash/{$fund->id}");

    $this->actingAsMember('acme', ['organization.manage']);
    $this->put($this->organizationUrl('acme', 'admin/settings/finance-limits'), ['petty_cash_max_payment' => '50'])->assertSessionHasNoErrors();
    expect($this->organization('acme')->fresh()->petty_cash_max_payment_minor)->toBe(5000)
        ->and(AuditEvent::query()->where('action', 'petty_cash.limit_changed')->count())->toBe(1);

    pcPay($fund);
    expect(pcBalance($fund))->toBe(9000);

    // Taking the limit away stops payments again.
    $this->actingAsMember('acme', ['organization.manage']);
    $this->put($this->organizationUrl('acme', 'admin/settings/finance-limits'), ['petty_cash_max_payment' => ''])->assertSessionHasNoErrors();
    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData())->assertSessionHasErrors('amount');
});

it('sends a payment to Finance as spending paid in cash, and stops at the limit, the box and the receipt', function () {
    $fund = pcFund();
    pcLimit(6000);

    $payment = pcPay($fund);
    expect(pcBalance($fund))->toBe(9000)
        ->and($payment->category?->value)->toBe('materials')
        ->and(pcSent('finance.project_expense.paid'))->toBe([[
            'reference' => PettyCash::paymentReference($payment),
            'paid_on' => now()->toDateString(),
            'currency' => $fund->currency,
            'amount_minor' => 1000,
            'description' => 'Petty cash Juba office: Printer paper',
            'category' => 'materials',
            'method' => 'cash',
            'supplier' => 'Stationers',
            'payment_reference' => null,
            'project' => null,
        ]]);
    Storage::disk('local')->assertExists($payment->receipt_path);

    $this->post(pcUrl("{$fund->id}/payments"), pcPayData(['amount' => '60.01']))->assertSessionHasErrors('amount');
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData(['receipt' => null]))->assertSessionHasErrors('receipt');
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData(['amount' => '0']))->assertSessionHasErrors('amount');
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData(['paid_on' => now()->addDay()->toDateString()]))->assertSessionHasErrors('paid_on');
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData(['category' => '']))->assertSessionHasErrors('category');
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData(['category' => 'labour']))->assertSessionHasErrors('category');

    // More than the box holds.
    pcPay($fund, ['amount' => '60']);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData(['amount' => '30.01']))->assertSessionHasErrors('amount');

    expect(pcBalance($fund))->toBe(3000)
        ->and(pcSent('finance.project_expense.paid'))->toHaveCount(2);
});

it('lets only the custodian pay, not even an owner', function () {
    $fund = pcFund();
    pcLimit(5000);

    $this->actingAsMember('acme', PC_CUSTODIAN);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData())->assertSessionHasErrors('amount');

    $this->actingAsMember('acme', owner: true);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData())->assertSessionHasErrors('amount');
    $this->get(pcUrl($fund->id))->assertInertia(fn (Assert $page) => $page->where('can.pay', false));

    $this->actingAs($this->manager);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData())->assertForbidden();

    expect(pcBalance($fund))->toBe(10000);
});

it('voids a payment until a top-up covers it', function () {
    $fund = pcFund();
    pcLimit(5000);
    $payment = pcPay($fund);
    $covered = pcPay($fund, ['amount' => '5']);

    $this->post(pcUrl("{$fund->id}/payments/{$payment->id}/reverse"), ['note' => ''])->assertSessionHasErrors('note');
    $this->post(pcUrl("{$fund->id}/payments/{$payment->id}/reverse"), ['note' => 'Wrong amount'])->assertSessionHasNoErrors();

    expect(pcBalance($fund))->toBe(9500)
        ->and(pcSent('finance.project_expense.voided'))->toBe([[
            'reference' => PettyCash::paymentReference($payment),
            'voided_on' => now()->toDateString(),
            'reason' => 'Wrong amount',
        ]]);

    $this->post(pcUrl("{$fund->id}/payments/{$payment->id}/reverse"), ['note' => 'Again'])->assertSessionHasErrors('payment');

    pcAskTopUp($fund);
    $this->post(pcUrl("{$fund->id}/payments/{$covered->id}/reverse"), ['note' => 'Too late'])->assertSessionHasErrors('payment');

    // A voided payment is not covered.
    expect($this->asTenant('acme', fn () => $payment->fresh()->top_up_id))->toBeNull()
        ->and(pcSent('finance.project_expense.voided'))->toHaveCount(1);
});

it('keeps others from voiding a payment', function () {
    $fund = pcFund();
    pcLimit(5000);
    $payment = pcPay($fund);

    $this->actingAsMember('acme', PC_CUSTODIAN);
    $this->post(pcUrl("{$fund->id}/payments/{$payment->id}/reverse"), ['note' => 'No'])->assertForbidden();

    $this->actingAs($this->manager);
    $this->post(pcUrl("{$fund->id}/payments/{$payment->id}/reverse"), ['note' => 'Yes'])->assertSessionHasNoErrors();
});

it('restores the set amount with a top-up checked by someone else', function () {
    $fund = pcFund();
    pcLimit(5000);
    $approver = $this->member('acme', PC_APPROVER);
    $one = pcPay($fund, ['amount' => '10']);
    $two = pcPay($fund, ['amount' => '15']);

    $topUp = pcAskTopUp($fund);
    expect($topUp->amount_minor)->toBe(2500)
        ->and($this->asTenant('acme', fn () => $one->fresh()->top_up_id))->toBe($topUp->id)
        ->and($this->asTenant('acme', fn () => $two->fresh()->top_up_id))->toBe($topUp->id)
        ->and(UserNotification::query()->where('user_id', $approver->id)->where('kind', UserNotification::APPROVAL)->count())->toBe(1);

    // Nothing moved yet, and one waits at a time.
    expect(pcBalance($fund))->toBe(7500)
        ->and(pcSent('finance.cash.moved'))->toHaveCount(1);
    $this->post(pcUrl("{$fund->id}/top-ups"))->assertSessionHasErrors('top_up');

    // Holding the permission is not enough for whoever holds the box or asked.
    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/approve"), pcApproveData())->assertForbidden();

    $this->actingAs($approver);
    $this->get(pcUrl($fund->id))->assertInertia(fn (Assert $page) => $page
        ->where('topUps.0.canDecide', true)
        ->where('fund.outstandingMinor', 2500)
        ->where('fund.differenceMinor', 0));

    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/approve"), pcApproveData())->assertSessionHasNoErrors();

    expect(pcBalance($fund))->toBe(10000)
        ->and(pcSent('finance.cash.moved')[1])->toMatchArray(['reference' => "petty-cash-top-up:{$topUp->id}", 'direction' => 'in', 'counterpart' => 'bank', 'amount_minor' => 2500])
        ->and($this->asTenant('acme', fn () => $topUp->fresh()->status))->toBe(PettyCashTopUp::APPROVED)
        ->and(UserNotification::query()->where('user_id', $this->custodian->id)->where('kind', UserNotification::DECISION)->count())->toBe(1);

    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/approve"), pcApproveData())->assertSessionHasErrors('top_up');
    $this->get(pcUrl($fund->id))->assertInertia(fn (Assert $page) => $page->where('fund.outstandingMinor', 0));
});

it('refuses a top-up decided by the custodian or whoever asked, even with the permission', function () {
    $fund = pcFund();
    pcLimit(5000);
    pcPay($fund);

    $this->custodian = $this->member('acme', [...PC_CUSTODIAN, 'finance.pettycash.approve'], user: $this->custodian);
    $topUp = pcAskTopUp($fund);

    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/approve"), pcApproveData())->assertSessionHasErrors('top_up');
    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/reject"), ['note' => 'No'])->assertSessionHasErrors('top_up');

    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/approve"), pcApproveData());
    expect(pcBalance($fund))->toBe(9000);

    // A manager who asked does not decide it either.
    $this->actingAsMember('acme', owner: true);
    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/reject"), ['note' => 'Bad receipt'])->assertSessionHasNoErrors();
    $owner = auth()->user();
    $this->post(pcUrl("{$fund->id}/top-ups"))->assertSessionHasNoErrors();
    $again = $this->asTenant('acme', fn () => PettyCashTopUp::query()->where('status', PettyCashTopUp::SUBMITTED)->sole());
    expect($again->requested_by)->toBe($owner->id);
    $this->post(pcUrl("{$fund->id}/top-ups/{$again->id}/approve"), pcApproveData())->assertSessionHasErrors('top_up');
});

it('puts a rejected top-up\'s payments back to waiting', function () {
    $fund = pcFund();
    pcLimit(5000);
    $payment = pcPay($fund);
    $topUp = pcAskTopUp($fund);

    $this->actingAsMember('acme', PC_APPROVER);
    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/reject"), ['note' => ''])->assertSessionHasErrors('note');
    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/reject"), ['note' => 'Unreadable'])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => $payment->fresh()->top_up_id))->toBeNull()
        ->and(pcBalance($fund))->toBe(9000);

    // The custodian voids it, and the cash is back.
    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/payments/{$payment->id}/reverse"), ['note' => 'Unreadable receipt'])->assertSessionHasNoErrors();
    expect(pcBalance($fund))->toBe(10000);
    $this->post(pcUrl("{$fund->id}/top-ups"))->assertSessionHasErrors('top_up');
});

it('counts by someone other than the custodian, and tells Finance of a difference', function () {
    $fund = pcFund();
    pcLimit(5000);
    pcPay($fund, ['amount' => '10']);

    $this->custodian = $this->member('acme', [...PC_CUSTODIAN, 'finance.pettycash.manage'], user: $this->custodian);
    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/counts"), ['counted' => '90', 'counted_on' => now()->toDateString()])->assertSessionHasErrors('counted');

    $this->actingAs($this->manager);
    $this->post(pcUrl("{$fund->id}/counts"), ['counted' => '90', 'counted_on' => now()->toDateString()])->assertSessionHasNoErrors();
    expect(pcSent('finance.cash.counted'))->toBe([]);

    // A shortage needs a word, then Finance moves it to cash over and short.
    $this->travel(1)->minutes();
    $this->post(pcUrl("{$fund->id}/counts"), ['counted' => '88', 'counted_on' => now()->toDateString()])->assertSessionHasErrors('note');
    $this->post(pcUrl("{$fund->id}/counts"), ['counted' => '88', 'counted_on' => now()->toDateString(), 'note' => 'Unexplained'])->assertSessionHasNoErrors();

    $counted = pcSent('finance.cash.counted');
    expect(pcBalance($fund))->toBe(8800)
        ->and($counted)->toHaveCount(1)
        ->and($counted[0])->toMatchArray(['expected_minor' => 9000, 'counted_minor' => 8800, 'difference_minor' => -200, 'note' => 'Unexplained', 'counted_by' => $this->manager->name]);

    $this->get(pcUrl($fund->id))->assertInertia(fn (Assert $page) => $page
        ->where('counts.0.differenceMinor', -200)
        ->where('fund.differenceMinor', -200));

    // The top-up brings it back to the set amount, shortage included.
    $topUp = pcAskTopUp($fund);
    expect($topUp->amount_minor)->toBe(1200);

    // More cash than expected goes the other way.
    $this->actingAs($this->manager);
    $this->post(pcUrl("{$fund->id}/counts"), ['counted' => '88.50', 'counted_on' => now()->toDateString(), 'note' => 'Found'])->assertSessionHasNoErrors();
    expect(pcBalance($fund))->toBe(8850)
        ->and(pcSent('finance.cash.counted')[1]['difference_minor'])->toBe(50);
});

// Projects

function pcProject(string $budget = '100'): Project
{
    $lead = test()->actingAsMember('acme', ['projects.view', 'projects.manage', 'projects.finance.view']);
    test()->post(test()->organizationUrl('acme', 'projects'), ['code' => 'wash', 'name' => 'Wash'])->assertSessionHasNoErrors();
    $project = test()->asTenant('acme', fn () => Project::query()->sole());
    test()->post(test()->organizationUrl('acme', "projects/{$project->id}/budget/lines"), ['category' => 'materials', 'amount' => $budget])->assertSessionHasNoErrors();
    $version = test()->asTenant('acme', fn () => $project->budgetVersions()->sole());
    test()->post(test()->organizationUrl('acme', "projects/{$project->id}/budget/versions/{$version->id}/submit"))->assertSessionHasNoErrors();
    test()->actingAsMember('acme', ['projects.view', 'projects.finance.view', 'finance.budgets.approve']);
    test()->post(test()->organizationUrl('acme', "projects/{$project->id}/budget/versions/{$version->id}/approve"))->assertSessionHasNoErrors();
    expect($lead)->toBeInstanceOf(User::class);

    return $project;
}

it('counts a payment charged to a project as that project\'s spending', function () {
    $fund = pcFund();
    pcLimit(5000);
    $project = pcProject();

    $payment = pcPay($fund, ['project_id' => $project->id, 'category' => 'materials']);

    $this->asTenant('acme', function () use ($payment, $project) {
        $expense = Expense::query()->sole();
        expect($expense->status)->toBe(ExpenseStatus::Paid)
            ->and($expense->project_id)->toBe($project->id)
            ->and($expense->amount_minor)->toBe(1000)
            ->and($expense->direct)->toBeTrue()
            ->and($payment->fresh()->expense_id)->toBe($expense->id)
            ->and($expense->documents()->count())->toBe(1);

        // It is voided in one place, so the two cannot disagree.
        expect(fn () => Spending::cancel($expense, 'No', test()->custodian))->toThrow(ValidationException::class, 'void it there');
    });

    // Sent once, from the box, on the project.
    $paid = pcSent('finance.project_expense.paid');
    expect($paid)->toHaveCount(1)
        ->and($paid[0])->toMatchArray(['reference' => PettyCash::paymentReference($payment), 'method' => 'cash', 'category' => 'materials', 'project' => ['code' => 'WASH', 'name' => 'Wash']])
        ->and(pcBalance($fund))->toBe(9000);

    $this->post(pcUrl("{$fund->id}/payments/{$payment->id}/reverse"), ['note' => 'Wrong project'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => Expense::query()->sole()->status))->toBe(ExpenseStatus::Cancelled)
        ->and(pcBalance($fund))->toBe(10000)
        ->and(pcSent('finance.project_expense.voided'))->toHaveCount(1);
});

it('holds a project payment to the approved budget', function () {
    $fund = pcFund();
    pcLimit(5000);
    $project = pcProject('15');
    $base = ['project_id' => $project->id, 'category' => 'materials'];

    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData([...$base, 'amount' => '16']))->assertSessionHasErrors('amount');
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData([...$base, 'category' => 'travel']))->assertSessionHasErrors('amount');
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData([...$base, 'category' => 'labour']))->assertSessionHasErrors('category');
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData([...$base, 'category' => '']))->assertSessionHasErrors('category');

    expect($this->asTenant('acme', fn () => Expense::query()->count()))->toBe(0)
        ->and($this->asTenant('acme', fn () => PettyCashPayment::query()->count()))->toBe(0)
        ->and(pcBalance($fund))->toBe(10000)
        ->and(pcSent('finance.project_expense.paid'))->toBe([]);
});

it('refuses a project with no approved budget', function () {
    $fund = pcFund();
    pcLimit(5000);
    $this->actingAsMember('acme', ['projects.view', 'projects.manage']);
    $this->post($this->organizationUrl('acme', 'projects'), ['code' => 'wash', 'name' => 'Wash'])->assertSessionHasNoErrors();
    $project = $this->asTenant('acme', fn () => Project::query()->sole());

    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData(['project_id' => $project->id, 'category' => 'materials']))->assertSessionHasErrors('project_id');
});

it('closes a box, returning what it holds to the bank', function () {
    $fund = pcFund();
    pcLimit(5000);
    pcPay($fund);
    $topUp = pcAskTopUp($fund);

    $this->actingAs($this->manager);
    $close = ['on' => now()->toDateString()];
    $this->post(pcUrl("{$fund->id}/close"), $close)->assertSessionHasErrors('close');

    $this->actingAsMember('acme', PC_APPROVER);
    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/reject"), ['note' => 'Closing'])->assertSessionHasNoErrors();

    $this->actingAs($this->manager);
    $this->post(pcUrl("{$fund->id}/close"), $close)->assertSessionHasNoErrors();

    $fresh = $this->asTenant('acme', fn () => $fund->fresh());
    expect($fresh->isActive())->toBeFalse()
        ->and(pcBalance($fund))->toBe(0)
        ->and(pcSent('finance.cash.moved')[1])->toMatchArray(['reference' => "petty-cash:{$fund->id}:close", 'direction' => 'out', 'counterpart' => 'bank', 'amount_minor' => 9000]);

    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData())->assertSessionHasErrors('fund');
    $this->post(pcUrl("{$fund->id}/top-ups"))->assertSessionHasErrors('fund');
});

it('hands a box over to someone who may hold one', function () {
    $fund = pcFund();
    $next = $this->member('acme', PC_CUSTODIAN);

    $this->put(pcUrl("{$fund->id}/custodian"), ['custodian_id' => $this->manager->id])->assertSessionHasErrors('custodian_id');
    $this->put(pcUrl("{$fund->id}/custodian"), ['custodian_id' => $next->id])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => $fund->fresh()->custodian_id))->toBe($next->id)
        ->and(AuditEvent::query()->where('action', 'petty_cash.custodian_changed')->count())->toBe(1);
});

// Who sees what

it('checks permission on each part', function () {
    $fund = pcFund();
    pcLimit(5000);
    $payment = pcPay($fund);
    $topUp = pcAskTopUp($fund);

    $this->actingAsMember('acme', PC_VIEW);
    $this->get(pcUrl())->assertOk();
    $this->get(pcUrl($fund->id))->assertOk();
    $this->get(pcUrl("{$fund->id}/payments/{$payment->id}/receipt"))->assertOk();
    $this->post(pcUrl(), ['name' => 'No'])->assertForbidden();
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData())->assertForbidden();
    $this->post(pcUrl("{$fund->id}/top-ups"))->assertForbidden();
    $this->post(pcUrl("{$fund->id}/counts"), ['counted' => '1', 'counted_on' => now()->toDateString()])->assertForbidden();
    $this->post(pcUrl("{$fund->id}/close"), [])->assertForbidden();
    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/reject"), ['note' => 'No'])->assertForbidden();

    $this->actingAsMember('acme', []);
    $this->get(pcUrl())->assertForbidden();
    $this->get(pcUrl($fund->id))->assertForbidden();
    $this->get(pcUrl("{$fund->id}/payments/{$payment->id}/receipt"))->assertForbidden();
});

it('finds a payment only through its own box', function () {
    $fund = pcFund();
    pcLimit(5000);
    $payment = pcPay($fund);

    $this->actingAs($this->manager);
    $this->post(pcUrl(), ['name' => 'Second', 'custodian_id' => $this->custodian->id, 'float' => '5', 'on' => now()->toDateString()])->assertSessionHasNoErrors();
    $second = $this->asTenant('acme', fn () => PettyCashFund::query()->where('name', 'Second')->sole());

    $this->get(pcUrl("{$second->id}/payments/{$payment->id}/receipt"))->assertNotFound();
});

it('stays off when petty cash and advances are off', function () {
    $this->actingAsMember('acme', owner: true);
    $this->put($this->organizationUrl('acme', 'admin/settings/modules/accounting'), ['enabled' => false])->assertSessionHasNoErrors();

    $this->get(pcUrl())->assertForbidden();
});

it('keeps another organization out', function () {
    $fund = pcFund();
    pcLimit(5000);
    $payment = pcPay($fund);
    $topUp = pcAskTopUp($fund);

    $this->organization('other');
    $this->actingAsMember('other', [...PC_MANAGER, 'finance.pettycash.approve', 'finance.pettycash.record']);

    $this->get(pcUrl('', 'other'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('funds', 0));
    $this->get(pcUrl($fund->id, 'other'))->assertNotFound();
    $this->get(pcUrl("{$fund->id}/payments/{$payment->id}/receipt", 'other'))->assertNotFound();
    $this->post(pcUrl("{$fund->id}/top-ups/{$topUp->id}/reject", 'other'), ['note' => 'No'])->assertNotFound();

    expect($this->financeEvents('other'))->toBe([]);
});

it('refuses another organization\'s records in the database', function () {
    $fund = pcFund();
    pcLimit(5000);
    $payment = pcPay($fund);

    $this->organization('other');
    $theirs = $this->asTenant('other', function () {
        $project = new Project(['code' => 'THEIRS', 'name' => 'Theirs', 'currency' => 'USD']);
        $project->forceFill(['status' => 'active', 'budget_minor' => 0, 'created_by' => test()->manager->id])->save();

        return $project;
    });

    expect(fn () => DB::transaction(fn () => DB::table('petty_cash_payments')->where('id', $payment->id)->update(['project_id' => $theirs->id])))
        ->toThrow(QueryException::class, 'petty_cash_payments_project_id_tenant_fk');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL enforces this');

/**
 * Acme takes Finance after setting up its boxes here.
 */
function pcTakeFinance(): void
{
    test()->platform()->notEntitledTo = [];
    Cache::flush();
}

it('keeps petty cash in Finance once the organization has it: boxes here only move', function () {
    $fund = pcFund();
    pcLimit(5000);
    pcTakeFinance();

    $financeUrl = FinanceLink::pettyCashUrl($this->organization('acme'));
    $this->actingAs($this->manager);
    $this->get(pcUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('financeUrl', $financeUrl)
        ->where('canManage', false));
    $this->get(pcUrl($fund->id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can.manage', false)
        ->where('can.count', false)
        ->where('can.requestTopUp', false)
        ->where('can.moveToFinance', true));

    $this->post(pcUrl(), ['name' => 'Wau', 'custodian_id' => $this->custodian->id, 'float' => '50', 'on' => now()->toDateString()])
        ->assertSessionHasErrors('fund');
    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData())->assertSessionHasErrors('fund');

    expect($this->asTenant('acme', fn () => PettyCashFund::query()->count()))->toBe(1)
        ->and(pcSent('finance.project_expense.paid'))->toBe([]);
});

it('moves a box into Finance holding what it holds, without moving the cash', function () {
    $fund = pcFund();
    pcLimit(5000);
    pcPay($fund);
    pcTakeFinance();

    $this->actingAs($this->manager);
    $this->post(pcUrl("{$fund->id}/move-to-finance"))->assertSessionHasNoErrors();

    $fund->refresh();
    expect($fund->status)->toBe(PettyCashFund::MOVED)
        ->and($fund->moved_at)->not->toBeNull()
        ->and(pcSent('finance.petty_cash.transferred'))->toBe([[
            'reference' => $fund->id,
            'name' => 'Juba office',
            'currency' => $fund->currency,
            'float_minor' => 10000,
            'held_minor' => 9000,
            'moved_on' => now()->toDateString(),
            'custodian' => $this->custodian->name,
        ]])
        ->and(pcSent('finance.cash.moved'))->toHaveCount(1) // only the set-up
        ->and(AuditEvent::query()->where('action', 'petty_cash.fund_moved_to_finance')->count())->toBe(1);

    // Read only here from now on; its receipts stay.
    $this->post(pcUrl("{$fund->id}/move-to-finance"))->assertSessionHasErrors('fund');
    $this->get(pcUrl($fund->id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('fund.active', false)
        ->where('can.moveToFinance', false)
        ->has('payments', 1));
    $this->actingAs($this->custodian);
    $this->post(pcUrl("{$fund->id}/payments"), pcPayData())->assertSessionHasErrors('fund');
    expect(pcSent('finance.petty_cash.transferred'))->toHaveCount(1);
});

it('moves a box only to an organization with Finance, and not with a top-up waiting', function () {
    $fund = pcFund();
    pcLimit(5000);
    pcPay($fund);

    $this->actingAs($this->manager);
    $this->post(pcUrl("{$fund->id}/move-to-finance"))->assertSessionHasErrors('fund');

    pcAskTopUp($fund);
    pcTakeFinance();
    $this->actingAs($this->manager);
    $this->post(pcUrl("{$fund->id}/move-to-finance"))->assertSessionHasErrors('fund');

    expect($fund->fresh()->status)->toBe(PettyCashFund::ACTIVE)
        ->and(pcSent('finance.petty_cash.transferred'))->toBe([]);
});

it('moves every box of an organization with Finance from the console', function () {
    $fund = pcFund();
    pcTakeFinance();

    $this->artisan('petty-cash:move-to-finance', ['--dry-run' => true])->assertSuccessful();
    expect($fund->fresh()->status)->toBe(PettyCashFund::ACTIVE);

    $this->artisan('petty-cash:move-to-finance')->assertSuccessful();
    expect($fund->fresh()->status)->toBe(PettyCashFund::MOVED)
        ->and(pcSent('finance.petty_cash.transferred'))->toHaveCount(1);

    $this->artisan('petty-cash:move-to-finance')->assertSuccessful();
    expect(pcSent('finance.petty_cash.transferred'))->toHaveCount(1);
});

it('leaves the boxes of an organization without Finance where they are', function () {
    $fund = pcFund();

    $this->artisan('petty-cash:move-to-finance')->assertSuccessful();

    expect($fund->fresh()->status)->toBe(PettyCashFund::ACTIVE)
        ->and(pcSent('finance.petty_cash.transferred'))->toBe([]);
});
