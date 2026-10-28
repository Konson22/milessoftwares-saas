<?php

use App\Models\Account;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

const ACCOUNTANT = [
    'finance.accounts.view', 'finance.accounts.manage', 'finance.journals.view', 'finance.journals.post',
    'finance.payables.view', 'finance.payables.manage', 'finance.receivables.view', 'finance.receivables.manage',
    'finance.banking.view', 'finance.banking.manage', 'finance.reports.view',
];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->setUpFinance('acme');
});

function account(string $key): Account
{
    return test()->asTenant('acme', fn () => Account::system($key));
}

function balance(string $key): int
{
    return test()->asTenant('acme', function () use ($key) {
        $account = Account::system($key);

        return $account->type->balance((int) $account->lines()->sum('debit_minor'), (int) $account->lines()->sum('credit_minor'));
    });
}

function acme(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path);
}

it('posts a balanced manual journal and refuses an unbalanced one', function () {
    $this->actingAsMember('acme', ACCOUNTANT);

    $this->post(acme('journals'), [
        'entry_date' => '2026-10-01',
        'memo' => 'Owner puts in capital',
        'lines' => [
            ['account_id' => account(Account::BANK)->id, 'debit' => '5000.00'],
            ['account_id' => account(Account::EQUITY)->id, 'credit' => '5000.00'],
        ],
    ])->assertRedirect();

    $this->post(acme('journals'), [
        'entry_date' => '2026-10-01',
        'memo' => 'Wrong',
        'lines' => [
            ['account_id' => account(Account::BANK)->id, 'debit' => '10.00'],
            ['account_id' => account(Account::EQUITY)->id, 'credit' => '9.00'],
        ],
    ])->assertSessionHasErrors('lines');

    expect(balance(Account::BANK))->toBe(500000)
        ->and(balance(Account::EQUITY))->toBe(500000);
});

it('reverses a manual journal once, but not a document\'s journal', function () {
    $this->actingAsMember('acme', ACCOUNTANT);

    $this->post(acme('journals'), [
        'entry_date' => '2026-10-01',
        'memo' => 'Rent',
        'lines' => [
            ['account_id' => $this->asTenant('acme', fn () => Account::query()->where('code', '6000')->sole()->id), 'debit' => '300'],
            ['account_id' => account(Account::CASH)->id, 'credit' => '300'],
        ],
    ]);
    $journal = $this->asTenant('acme', fn () => Journal::query()->sole());

    $this->post(acme("journals/{$journal->id}/reverse"), ['entry_date' => '2026-10-02', 'reason' => 'Paid by bank'])->assertRedirect();
    $this->post(acme("journals/{$journal->id}/reverse"), ['entry_date' => '2026-10-02', 'reason' => 'Again'])->assertSessionHasErrors('journal');

    expect(balance(Account::CASH))->toBe(0);

    $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Landlord']));
    $this->post(acme('bills'), [
        'supplier_id' => $supplier->id, 'bill_date' => '2026-10-01',
        'lines' => [['account_id' => $this->asTenant('acme', fn () => Account::query()->where('code', '6000')->sole()->id), 'description' => 'October rent', 'amount' => '300']],
    ]);
    $billJournal = $this->asTenant('acme', fn () => Journal::query()->where('source_type', 'bill')->sole());

    $this->post(acme("journals/{$billJournal->id}/reverse"), ['entry_date' => '2026-10-02', 'reason' => 'No'])->assertSessionHasErrors('journal');
});

it('records a bill and pays it, in part and then in full', function () {
    $this->actingAsMember('acme', ACCOUNTANT);
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Power company']));
    $utilities = $this->asTenant('acme', fn () => Account::query()->where('code', '6200')->sole());

    $this->post(acme('bills'), [
        'supplier_id' => $supplier->id, 'bill_date' => '2026-10-01', 'due_on' => '2026-10-31',
        'lines' => [['account_id' => $utilities->id, 'description' => 'Electricity', 'amount' => '120.50']],
        'tax' => '9.50',
    ])->assertRedirect();
    $bill = $this->asTenant('acme', fn () => Bill::query()->sole());
    expect($bill->total_minor)->toBe(13000)->and(balance(Account::PAYABLE))->toBe(13000);

    $pay = fn (string $amount, string $allocated) => $this->post(acme('supplier-payments'), [
        'supplier_id' => $supplier->id, 'account_id' => account(Account::BANK)->id, 'amount' => $amount,
        'paid_on' => '2026-10-15', 'allocations' => [$bill->id => $allocated],
    ]);

    $pay('100', '100')->assertRedirect();
    $pay('50', '50')->assertSessionHasErrors('allocations'); // only 30 left
    $pay('30', '30')->assertRedirect();

    expect($this->asTenant('acme', fn () => $bill->refresh()->status()))->toBe('paid')
        ->and(balance(Account::PAYABLE))->toBe(0)
        ->and(balance(Account::BANK))->toBe(-13000);
});

it('refuses to set a payment against another supplier\'s bill', function () {
    $this->actingAsMember('acme', ACCOUNTANT);
    [$mine, $theirs] = $this->asTenant('acme', fn () => [Supplier::query()->create(['name' => 'A']), Supplier::query()->create(['name' => 'B'])]);
    $this->post(acme('bills'), [
        'supplier_id' => $theirs->id, 'bill_date' => '2026-10-01',
        'lines' => [['account_id' => $this->asTenant('acme', fn () => Account::query()->where('code', '6900')->sole()->id), 'description' => 'X', 'amount' => '10']],
    ]);
    $bill = $this->asTenant('acme', fn () => Bill::query()->sole());

    $this->post(acme('supplier-payments'), [
        'supplier_id' => $mine->id, 'account_id' => account(Account::CASH)->id, 'amount' => '10',
        'paid_on' => '2026-10-15', 'allocations' => [$bill->id => '10'],
    ])->assertSessionHasErrors('allocations');

    $this->post(acme('supplier-payments'), [
        'supplier_id' => $mine->id, 'account_id' => account(Account::CASH)->id, 'amount' => '10',
        'paid_on' => '2026-10-15', 'allocations' => ['not-a-bill' => '10'],
    ])->assertSessionHasErrors('allocations');
});

it('raises an invoice, receives payment, and voids only unpaid invoices raised here', function () {
    $this->actingAsMember('acme', ACCOUNTANT);
    $other = $this->asTenant('acme', fn () => Account::query()->where('code', '4900')->sole());

    $raise = fn () => $this->post(acme('invoices'), [
        'customer_name' => 'Juba Clinic', 'issued_on' => '2026-10-01',
        'lines' => [['account_id' => $other->id, 'description' => 'Training', 'amount' => '200']],
    ]);
    $raise()->assertRedirect();
    $raise()->assertRedirect();
    [$paid, $unpaid] = $this->asTenant('acme', fn () => Invoice::query()->orderBy('number')->get()->all());

    $this->post(acme("invoices/{$paid->id}/payments"), [
        'account_id' => account(Account::MOBILE_MONEY)->id, 'method' => 'mobile_money', 'amount' => '250', 'received_on' => '2026-10-05',
    ])->assertSessionHasErrors('amount');
    $this->post(acme("invoices/{$paid->id}/payments"), [
        'account_id' => account(Account::MOBILE_MONEY)->id, 'method' => 'mobile_money', 'amount' => '200', 'received_on' => '2026-10-05',
    ])->assertRedirect();

    $this->post(acme("invoices/{$paid->id}/void"), ['reason' => 'No'])->assertSessionHasErrors('reason');
    $this->post(acme("invoices/{$unpaid->id}/void"), ['reason' => 'Raised twice'])->assertRedirect();

    expect(balance(Account::RECEIVABLE))->toBe(0)
        ->and(balance(Account::MOBILE_MONEY))->toBe(20000)
        ->and($this->asTenant('acme', fn () => $unpaid->refresh()->status()))->toBe('void');
});

it('counts a document overdue only from the day after it is due', function () {
    $this->travelTo('2026-10-20 15:00');
    $this->actingAsMember('acme', ACCOUNTANT);
    $other = $this->asTenant('acme', fn () => Account::query()->where('code', '4900')->sole());

    foreach (['2026-10-20', '2026-10-19'] as $due) {
        $this->post(acme('invoices'), [
            'customer_name' => "Due {$due}", 'issued_on' => '2026-10-01', 'due_on' => $due,
            'lines' => [['account_id' => $other->id, 'description' => 'Training', 'amount' => '200']],
        ])->assertRedirect();
    }

    $statuses = $this->asTenant('acme', fn () => Invoice::query()->get()->mapWithKeys(fn (Invoice $invoice) => [$invoice->due_on->toDateString() => $invoice->status()])->all());
    expect($statuses)->toBe(['2026-10-20' => 'open', '2026-10-19' => 'overdue']);
});

it('searches journals, invoices and bills whatever the case', function () {
    $this->actingAsMember('acme', ACCOUNTANT);
    $rent = $this->asTenant('acme', fn () => Account::query()->where('code', '6000')->sole());
    $this->post(acme('journals'), [
        'entry_date' => '2026-10-01', 'memo' => 'October Rent',
        'lines' => [['account_id' => $rent->id, 'debit' => '300'], ['account_id' => account(Account::CASH)->id, 'credit' => '300']],
    ])->assertRedirect();
    $this->post(acme('invoices'), [
        'customer_name' => 'Juba Clinic', 'issued_on' => '2026-10-01',
        'lines' => [['account_id' => $this->asTenant('acme', fn () => Account::query()->where('code', '4900')->sole()->id), 'description' => 'Sublet', 'amount' => '50']],
    ])->assertSessionHasNoErrors();

    $this->get(acme('journals?from=2026-10-01&to=2026-10-31&search=rent'))
        ->assertInertia(fn (Assert $page) => $page->has('journals', 1));
    $this->get(acme('invoices?status=all&search=JUBA'))
        ->assertInertia(fn (Assert $page) => $page->has('invoices', 1));
});

it('moves money in, out and between accounts', function () {
    $this->actingAsMember('acme', ACCOUNTANT);
    $charges = $this->asTenant('acme', fn () => Account::query()->where('code', '6300')->sole());
    $income = $this->asTenant('acme', fn () => Account::query()->where('code', '4900')->sole());

    $this->post(acme('banking/money-in'), ['account_id' => account(Account::CASH)->id, 'other_account_id' => $income->id, 'amount' => '100', 'entry_date' => '2026-10-01', 'description' => 'Sold old shelf'])->assertRedirect();
    $this->post(acme('banking/transfers'), ['account_id' => account(Account::CASH)->id, 'to_account_id' => account(Account::BANK)->id, 'amount' => '80', 'entry_date' => '2026-10-02', 'description' => 'Banked'])->assertRedirect();
    $this->post(acme('banking/money-out'), ['account_id' => account(Account::BANK)->id, 'other_account_id' => $charges->id, 'amount' => '2', 'entry_date' => '2026-10-03', 'description' => 'Fee'])->assertRedirect();
    $this->post(acme('banking/transfers'), ['account_id' => account(Account::BANK)->id, 'to_account_id' => account(Account::BANK)->id, 'amount' => '1', 'entry_date' => '2026-10-03', 'description' => 'Loop'])->assertSessionHasErrors('to_account_id');
    $this->post(acme('banking/money-out'), ['account_id' => $charges->id, 'other_account_id' => $income->id, 'amount' => '1', 'entry_date' => '2026-10-03', 'description' => 'Not a bank'])->assertSessionHasErrors('account_id');

    expect(balance(Account::CASH))->toBe(2000)->and(balance(Account::BANK))->toBe(7800);
});

it('produces statements that balance', function () {
    $this->actingAsMember('acme', ACCOUNTANT);
    $this->post(acme('journals'), ['entry_date' => '2026-10-01', 'memo' => 'Capital', 'lines' => [
        ['account_id' => account(Account::BANK)->id, 'debit' => '1000'],
        ['account_id' => account(Account::EQUITY)->id, 'credit' => '1000'],
    ]]);
    $this->post(acme('journals'), ['entry_date' => '2026-10-02', 'memo' => 'Cash sale', 'lines' => [
        ['account_id' => account(Account::CASH)->id, 'debit' => '300'],
        ['account_id' => account(Account::SALES)->id, 'credit' => '300'],
    ]]);
    $this->post(acme('journals'), ['entry_date' => '2026-10-03', 'memo' => 'Stock', 'lines' => [
        ['account_id' => account(Account::PURCHASES)->id, 'debit' => '120'],
        ['account_id' => account(Account::PAYABLE)->id, 'credit' => '120'],
    ]]);

    $this->get(acme('reports/trial-balance?as_of=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/trial-balance')
        ->where('debitMinor', 142000)
        ->where('creditMinor', 142000));

    $this->get(acme('reports/profit-and-loss?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->where('revenueMinor', 30000)
        ->where('expensesMinor', 12000)
        ->where('profitMinor', 18000));

    $this->get(acme('reports/balance-sheet?as_of=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->where('assetsMinor', 130000)
        ->where('liabilitiesMinor', 12000)
        ->where('earningsMinor', 18000)
        ->where('equityMinor', 118000));
});

it('shows every page to an accountant', function (string $path, string $component) {
    $this->actingAsMember('acme', ACCOUNTANT);

    $this->get(acme($path))->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['', 'home'],
    ['accounts', 'accounts/index'],
    ['journals', 'journals/index'],
    ['journals/create', 'journals/create'],
    ['suppliers', 'suppliers/index'],
    ['bills', 'bills/index'],
    ['bills/create', 'bills/create'],
    ['supplier-payments', 'supplier-payments/index'],
    ['supplier-payments/create', 'supplier-payments/create'],
    ['invoices', 'invoices/index'],
    ['invoices/create', 'invoices/create'],
    ['customer-payments', 'customer-payments/index'],
    ['banking', 'banking/index'],
    ['reports/trial-balance', 'reports/trial-balance'],
    ['reports/profit-and-loss', 'reports/profit-and-loss'],
    ['reports/balance-sheet', 'reports/balance-sheet'],
]);

it('lets an auditor read but not post', function () {
    $this->actingAsMember('acme', ['finance.accounts.view', 'finance.journals.view', 'finance.reports.view']);

    $this->get(acme('accounts'))->assertOk();
    $this->get(acme('journals/create'))->assertForbidden();
    $this->post(acme('journals'), [])->assertForbidden();
    $this->get(acme('bills'))->assertForbidden();
    $this->get(acme())->assertInertia(fn (Assert $page) => $page->where('cash', null)->where('payable', null)->has('month'));
});

it('keeps posted lines unchangeable and journals balanced in the database', function () {
    $this->actingAsMember('acme', ACCOUNTANT);
    $this->post(acme('journals'), ['entry_date' => '2026-10-01', 'memo' => 'Capital', 'lines' => [
        ['account_id' => account(Account::BANK)->id, 'debit' => '10'],
        ['account_id' => account(Account::EQUITY)->id, 'credit' => '10'],
    ]]);
    $line = $this->asTenant('acme', fn () => JournalLine::query()->first());

    expect(fn () => DB::transaction(fn () => DB::table('journal_lines')->where('id', $line->id)->update(['debit_minor' => 99])))
        ->toThrow(QueryException::class);
    // The balance is checked at commit; tests never commit, so check now.
    expect(fn () => DB::transaction(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE') && DB::table('journal_lines')->insert([
        'id' => (string) str()->uuid(), 'organization_id' => $line->organization_id, 'journal_id' => $line->journal_id,
        'account_id' => $line->account_id, 'position' => 9, 'debit_minor' => 5, 'credit_minor' => 0,
    ])))->toThrow(QueryException::class);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL enforces this');
