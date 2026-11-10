<?php

use App\Enums\BankKind;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\DimensionValue;
use App\Models\ExchangeRate;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\PettyCashFund;

/*
| Projects (NGOs) sends project spending, grant payments and staff advances.
| Petty cash comes as finance.cash.moved and finance.cash.counted, as a till.
*/

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
});

function projectsBalance(string $systemKey): int
{
    return test()->asTenant('acme', function () use ($systemKey) {
        $account = Account::query()->where('system_key', $systemKey)->first();
        if ($account === null) {
            return 0;
        }
        $lines = $account->lines()->get();

        return $account->type->balance((int) $lines->sum('debit_minor'), (int) $lines->sum('credit_minor'));
    });
}

function projectExpense(array $overrides = [], string $reference = 'EXP-1')
{
    return test()->sendEvent('finance.project_expense.paid', "expense:{$reference}:paid", $overrides + [
        'reference' => $reference,
        'paid_on' => '2026-03-10',
        'currency' => 'SSP',
        'amount_minor' => 250_000,
        'description' => 'Hand pumps',
        'category' => 'materials',
        'method' => 'bank_transfer',
        'supplier' => 'Pumps Ltd',
        'project' => ['code' => 'WASH', 'name' => 'Water for Juba'],
    ], source: 'projects');
}

it('posts project spending to its category, tagged with the project, and voids it', function () {
    projectExpense()->assertCreated();

    expect(projectsBalance(Account::PROJECT_MATERIALS))->toBe(250_000)
        ->and(projectsBalance(Account::BANK))->toBe(-250_000);

    $this->asTenant('acme', function () {
        $project = DimensionValue::query()->where('kind', DimensionValue::PROJECT)->sole();
        expect($project->code)->toBe('WASH')->and($project->name)->toBe('Water for Juba')
            ->and(JournalLine::query()->where('project_id', $project->id)->count())->toBe(2)
            ->and(Account::query()->where('system_key', Account::PROJECT_MATERIALS)->value('code'))->toBe('6510');
    });

    $this->sendEvent('finance.project_expense.voided', 'expense:EXP-1:voided', [
        'reference' => 'EXP-1', 'voided_on' => '2026-03-12', 'reason' => 'Paid twice',
    ], source: 'projects')->assertCreated();

    expect(projectsBalance(Account::PROJECT_MATERIALS))->toBe(0)
        ->and(projectsBalance(Account::BANK))->toBe(0);
});

it('waits for the payment before its void, and for a rate before foreign spending', function () {
    $this->sendEvent('finance.project_expense.voided', 'expense:EXP-9:voided', [
        'reference' => 'EXP-9', 'voided_on' => '2026-03-12', 'reason' => 'Early',
    ], source: 'projects')->assertStatus(503);

    projectExpense(['currency' => 'USD', 'amount_minor' => 100_00], 'EXP-2')->assertStatus(503);

    $this->asTenant('acme', fn () => ExchangeRate::query()->create(['currency' => 'USD', 'rate_on' => '2026-03-01', 'rate' => '1000']));
    projectExpense(['currency' => 'USD', 'amount_minor' => 100_00], 'EXP-2')->assertCreated();
    expect(projectsBalance(Account::PROJECT_MATERIALS))->toBe(10_000_000);
});

it('pays spending from a staff advance out of employee advances, with no project', function () {
    $this->sendEvent('finance.staff_advance.issued', 'advance:ADV-1:issued', [
        'advance_reference' => 'ADV-1', 'issued_on' => '2026-03-01', 'currency' => 'SSP',
        'amount_minor' => 100_000, 'method' => 'cash', 'held_by' => 'Ayen Deng', 'purpose' => 'Field trip',
    ], source: 'projects')->assertCreated();
    expect(projectsBalance(Account::EMPLOYEE_ADVANCES))->toBe(100_000)
        ->and(projectsBalance(Account::CASH))->toBe(-100_000);

    projectExpense(['method' => 'staff_advance', 'category' => 'travel', 'amount_minor' => 70_000, 'project' => null], 'ADV-1-L1')->assertCreated();

    $this->sendEvent('finance.staff_advance.settled', 'advance:ADV-1:returned', [
        'reference' => 'ADV-1-R1', 'advance_reference' => 'ADV-1', 'settled_on' => '2026-03-05', 'currency' => 'SSP',
        'amount_minor' => 20_000, 'kind' => 'returned', 'method' => 'cash',
    ], source: 'projects')->assertCreated();
    $this->sendEvent('finance.staff_advance.settled', 'advance:ADV-1:written-off', [
        'reference' => 'ADV-1-W1', 'advance_reference' => 'ADV-1', 'settled_on' => '2026-03-06', 'currency' => 'SSP',
        'amount_minor' => 10_000, 'kind' => 'written_off', 'note' => 'Lost receipt',
    ], source: 'projects')->assertCreated();

    expect(projectsBalance(Account::EMPLOYEE_ADVANCES))->toBe(0)
        ->and(projectsBalance(Account::PROJECT_TRAVEL))->toBe(70_000)
        ->and(projectsBalance(Account::ADVANCES_WRITTEN_OFF))->toBe(10_000)
        ->and(projectsBalance(Account::CASH))->toBe(-80_000);
    $this->asTenant('acme', fn () => expect(DimensionValue::query()->count())->toBe(0));
});

it('books grant payments as grant income', function () {
    $this->sendEvent('finance.grant.received', 'grant-payment:GP-1', [
        'reference' => 'GP-1', 'grant_reference' => 'ECHO/1', 'grant_title' => 'Clean water', 'funder' => 'ECHO',
        'received_on' => '2026-03-02', 'currency' => 'SSP', 'amount_minor' => 5_000_000,
    ], source: 'projects')->assertCreated();

    expect(projectsBalance(Account::GRANT_INCOME))->toBe(5_000_000)
        ->and(projectsBalance(Account::BANK))->toBe(5_000_000);
    $this->asTenant('acme', fn () => expect(Journal::query()->sole()->source_reference)->toBe('GP-1'));
});

it('refuses what it cannot post, and gives other organizations none of the project accounts', function () {
    projectExpense(['category' => 'luxury'])->assertUnprocessable();
    projectExpense(['method' => 'cheque'])->assertUnprocessable();

    $this->asTenant('acme', fn () => expect(Account::query()->whereIn('system_key', [Account::GRANT_INCOME, Account::PROJECT_MATERIALS, Account::ADVANCES_WRITTEN_OFF])->count())->toBe(0));
});

function movedBox(array $overrides = [], string $id = 'b1f0c0de-0000-4000-8000-000000000001')
{
    return test()->sendEvent('finance.petty_cash.transferred', "petty-cash:{$id}:moved", $overrides + [
        'reference' => $id,
        'name' => 'Juba office',
        'currency' => 'SSP',
        'float_minor' => 50_000,
        'held_minor' => 30_000,
        'moved_on' => '2026-03-15',
        'custodian' => 'A. Custodian',
    ], source: 'projects');
}

it('turns a moved Projects box into a fund on its own account, taking what it held out of Cash', function () {
    movedBox()->assertCreated();

    $this->asTenant('acme', function () {
        $fund = PettyCashFund::query()->with('account')->sole();
        expect($fund->name)->toBe('Juba office')
            ->and($fund->custodian_id)->toBeNull()
            ->and($fund->float_minor)->toBe(50_000)
            ->and($fund->source_reference)->toBe('projects:petty-cash:b1f0c0de-0000-4000-8000-000000000001')
            ->and($fund->account->bank_kind)->toBe(BankKind::PettyCash)
            ->and($fund->account->code)->toBe('1041')
            ->and($fund->account->heldMinor())->toBe(30_000)
            ->and(AuditEvent::query()->where('action', 'petty_cash.fund_moved_in')->count())->toBe(1);
    });
    expect(projectsBalance(Account::CASH))->toBe(-30_000);

    // Sent again (a retry under another id): nothing twice.
    $this->sendEvent('finance.petty_cash.transferred', 'petty-cash:again', [
        'reference' => 'b1f0c0de-0000-4000-8000-000000000001', 'name' => 'Juba office', 'currency' => 'SSP',
        'float_minor' => 50_000, 'held_minor' => 30_000, 'moved_on' => '2026-03-15',
    ], source: 'projects')->assertCreated();
    expect($this->asTenant('acme', fn () => PettyCashFund::query()->count()))->toBe(1)
        ->and(projectsBalance(Account::CASH))->toBe(-30_000);
});

it('keeps a moved box in its own currency, converted at the day\'s rate', function () {
    movedBox(['currency' => 'USD', 'held_minor' => 100_00, 'float_minor' => 200_00])->assertStatus(503);

    $this->asTenant('acme', fn () => ExchangeRate::query()->create(['currency' => 'USD', 'rate_on' => '2026-03-01', 'rate' => '1000']));
    movedBox(['currency' => 'USD', 'held_minor' => 100_00, 'float_minor' => 200_00])->assertCreated();

    $this->asTenant('acme', function () {
        $account = PettyCashFund::query()->with('account')->sole()->account;
        expect($account->currency)->toBe('USD')
            ->and($account->heldMinor())->toBe(100_00);
    });
    expect(projectsBalance(Account::CASH))->toBe(-10_000_000);
});

it('names a second box apart from a fund Finance already has', function () {
    movedBox()->assertCreated();
    movedBox([], 'b1f0c0de-0000-4000-8000-000000000002')->assertCreated();

    expect($this->asTenant('acme', fn () => PettyCashFund::query()->orderBy('name')->pluck('name')->all()))->toBe(['Juba office', 'Juba office (Projects)']);
});
