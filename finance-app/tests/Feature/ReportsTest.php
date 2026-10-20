<?php

use App\Models\Account;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

const REPORTER = [
    'finance.accounts.view', 'finance.accounts.manage', 'finance.journals.view', 'finance.journals.post',
    'finance.reports.view', 'finance.periods.manage',
];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
    $this->actingAsMember('acme', REPORTER);
});

function reportUrl(string $path): string
{
    return test()->workspaceUrl('acme', $path);
}

function accountIdFor(string $code): string
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole()->id);
}

function entry(string $date, string $debit, string $credit, string $amount, string $memo = 'Entry'): void
{
    test()->post(reportUrl('journals'), [
        'entry_date' => $date,
        'memo' => $memo,
        'lines' => [
            ['account_id' => accountIdFor($debit), 'debit' => $amount],
            ['account_id' => accountIdFor($credit), 'credit' => $amount],
        ],
    ])->assertSessionHasNoErrors();
}

function invoiceEvent(string $number, string $issued, ?string $due, int $total, string $customer = 'Akol Stores'): void
{
    test()->sendEvent('finance.invoice.issued', "sale:{$number}:invoice", [
        'invoice_number' => $number, 'branch_id' => null, 'customer_reference' => $customer, 'currency' => 'SSP',
        'total_minor' => $total, 'tax_minor' => 0, 'due_on' => $due,
        'lines' => [['description' => 'Goods', 'quantity' => 1, 'amount_minor' => $total, 'revenue_account' => null]],
    ], occurredAt: "{$issued}T09:00:00+00:00")->assertCreated();
}

it('lists the general ledger with opening, running and closing balances', function () {
    entry('2026-09-30', '1010', '3000', '1000', 'Capital');
    entry('2026-10-05', '6000', '1010', '300', 'Rent');
    entry('2026-10-07', '6200', '1010', '50', 'Power');

    $this->get(reportUrl('reports/general-ledger?from=2026-10-01&to=2026-10-31'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/general-ledger')
            ->where('debitMinor', 35000)
            ->where('creditMinor', 35000)
            ->where('accounts.0.code', '1010')
            ->where('accounts.0.openingMinor', 100000)
            ->where('accounts.0.lines.0.balanceMinor', 70000)
            ->where('accounts.0.lines.1.balanceMinor', 65000)
            ->where('accounts.0.closingMinor', 65000)
            // Equity had no movement but a balance.
            ->where('accounts.1.code', '3000')
            ->where('accounts.1.lines', [])
            ->has('accounts', 4));

    $this->get(reportUrl('reports/general-ledger?from=2026-10-01&to=2026-10-31&account='.accountIdFor('6000')))
        ->assertInertia(fn (Assert $page) => $page->has('accounts', 1)->where('accounts.0.closingMinor', 30000));

    $csv = $this->get(reportUrl('reports/general-ledger?from=2026-10-01&to=2026-10-31&export=lines'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->streamedContent();

    expect($csv)->toContain('1010,"Bank account",2026-10-01,,"Opening balance",,,,,1000.00')
        ->and($csv)->toContain('"Closing balance",,,0.00,350.00,650.00');
});

it('ages receivables by days past due and agrees with the receivable account', function () {

    invoiceEvent('S-1', '2026-10-15', '2026-11-14', 10000);             // not yet due
    invoiceEvent('S-2', '2026-09-01', '2026-09-30', 20000);             // 20 days
    invoiceEvent('S-3', '2026-06-01', null, 30000, 'Mary Deng');         // 141 days, from its date
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00'));

    $this->get(reportUrl('reports/aged-receivables'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/aged')
            ->where('totals.currentMinor', 10000)
            ->where('totals.days30Minor', 20000)
            ->where('totals.olderMinor', 30000)
            ->where('totals.totalMinor', 60000)
            ->where('accountMinor', 60000)
            ->where('differenceMinor', 0)
            ->where('parties.0.party', 'Akol Stores')
            ->where('parties.0.totalMinor', 30000)
            ->where('documents.0.number', 'S-3')
            ->where('documents.0.daysOverdue', 141));

    // Money in the account that is on no invoice shows as the difference.
    entry('2026-10-01', '1200', '3900', '5', 'Opening receivable');
    $this->get(reportUrl('reports/aged-receivables'))
        ->assertInertia(fn (Assert $page) => $page->where('accountMinor', 60500)->where('differenceMinor', 500));

    expect($this->get(reportUrl('reports/aged-receivables?export=documents'))->streamedContent())
        ->toContain('"Mary Deng",S-3,2026-06-01,,141,300.00,300.00');
});

it('ages payables from bills, less what was paid', function () {

    $this->sendEvent('finance.bill.received', 'goods_receipt:1', [
        'bill_number' => 'GRN-1', 'supplier_name' => 'Juba Medical Supplies', 'currency' => 'SSP',
        'total_minor' => 40000, 'tax_minor' => 0, 'received_on' => '2026-10-12', 'due_on' => '2026-11-11',
        'lines' => [['description' => 'Amoxicillin', 'quantity' => 100, 'amount_minor' => 40000, 'expense_account' => null]],
    ])->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-12-20 12:00'));

    $this->get(reportUrl('reports/aged-payables'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kind', 'payables')
            ->where('totals.days60Minor', 40000)
            ->where('accountMinor', 40000)
            ->where('differenceMinor', 0)
            ->where('parties.0.party', 'Juba Medical Supplies'));
});

it('builds the cash flow statement from profit to the change in bank and cash', function () {
    entry('2026-01-01', '1010', '1000', '0.01', 'Float');          // between cash accounts: no flow
    entry('2026-01-02', '1010', '3000', '5000', 'Owner capital');  // financing
    entry('2026-01-03', '1200', '4000', '1000', 'Sale on credit'); // profit, receivable up
    entry('2026-01-04', '1010', '1200', '600', 'Customer pays');   // receivable down
    entry('2026-01-05', '1500', '1010', '2000', 'Equipment');      // investing
    entry('2026-01-06', '6000', '2000', '300', 'Rent on account'); // profit down, payable up

    $this->get(reportUrl('reports/cash-flow?from=2026-01-01&to=2026-12-31'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/cash-flow')
            ->where('profitMinor', 70000)
            ->where('operating.0.code', '1200')
            ->where('operating.0.amountMinor', -40000)
            ->where('operating.1.code', '2000')
            ->where('operating.1.amountMinor', 30000)
            ->where('operatingMinor', 60000)
            ->where('investingMinor', -200000)
            ->where('financingMinor', 500000)
            ->where('netChangeMinor', 360000)
            ->where('openingCashMinor', 0)
            ->where('closingCashMinor', 360000));

    // The accountant moves equipment into operating activities.
    $equipment = $this->asTenant('acme', fn () => Account::query()->where('code', '1500')->sole());
    $this->put(reportUrl("accounts/{$equipment->id}"), [
        'code' => '1500', 'name' => 'Equipment', 'type' => 'asset', 'cash_flow' => 'operating', 'active' => true,
    ])->assertSessionHasNoErrors();

    $this->get(reportUrl('reports/cash-flow?from=2026-01-01&to=2026-12-31'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('investingMinor', 0)
            ->where('operatingMinor', -140000)
            ->where('netChangeMinor', 360000));
});

it('leaves a year-end close out of the cash flow', function () {
    $this->post(reportUrl('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12]);
    entry('2026-03-01', '1010', '4000', '800', 'Cash sale');
    $year = $this->asTenant('acme', fn () => FiscalYear::query()->sole());
    $this->post(reportUrl("periods/years/{$year->id}/close"))->assertSessionHasNoErrors();

    $this->get(reportUrl('reports/cash-flow?from=2026-01-01&to=2026-12-31'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profitMinor', 80000)
            ->where('financing', [])
            ->where('netChangeMinor', 80000)
            ->where('closingCashMinor', 80000));
});

it('compares profit and loss with the previous period and the same period last year', function () {
    entry('2025-10-10', '1010', '4000', '400', 'Last year');
    entry('2026-09-10', '1010', '4000', '300', 'Last month');
    entry('2026-10-10', '1010', '4000', '500', 'This month');
    entry('2026-10-11', '6000', '1010', '100', 'Rent');

    $this->get(reportUrl('reports/profit-and-loss?from=2026-10-01&to=2026-10-31&compare=previous_period'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('compareLabel', '2026-09-01 to 2026-09-30')
            ->where('revenue.0.amountMinor', 50000)
            ->where('revenue.0.compareMinor', 30000)
            // In this period only: compared with zero.
            ->where('expenses.0.compareMinor', 0)
            ->where('compare.profitMinor', 30000)
            ->where('profitMinor', 40000));

    $this->get(reportUrl('reports/profit-and-loss?from=2026-10-01&to=2026-10-31&compare=previous_year'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('compareLabel', '2025-10-01 to 2025-10-31')
            ->where('compare.revenueMinor', 40000));

    $this->get(reportUrl('reports/profit-and-loss?compare=yesterday'))->assertSessionHasErrors('compare');

    expect($this->get(reportUrl('reports/profit-and-loss?from=2026-10-01&to=2026-10-31&compare=previous_period&export=statement'))->streamedContent())
        ->toContain('Section,Code,Account,Amount,"Compared with"')
        ->toContain('Revenue,4000,"Sales revenue",500.00,300.00');
});

it('compares the balance sheet with the end of the previous month', function () {
    entry('2026-09-10', '1010', '3000', '1000', 'Capital');
    entry('2026-10-10', '1010', '4000', '250', 'Sale');

    $this->get(reportUrl('reports/balance-sheet?as_of=2026-10-20&compare=previous_month'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('compareLabel', '2026-09-30')
            ->where('assets.0.amountMinor', 125000)
            ->where('assets.0.compareMinor', 100000)
            ->where('compare.earningsMinor', 0)
            ->where('earningsMinor', 25000));
});

it('downloads the trial balance, and keeps reports from members without the permission', function () {
    entry('2026-10-01', '1010', '3000', '1000', 'Capital');

    expect($this->get(reportUrl('reports/trial-balance?as_of=2026-10-31&export=accounts'))->streamedContent())
        ->toContain('Code,Account,Type,Debit,Credit')
        ->toContain('1010,"Bank account",asset,1000.00,');

    $this->actingAsMember('acme', ['finance.journals.view']);
    foreach (['cash-flow', 'general-ledger', 'aged-receivables', 'aged-payables'] as $report) {
        $this->get(reportUrl("reports/{$report}"))->assertForbidden();
    }
});
