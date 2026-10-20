<?php

use App\Models\Account;
use App\Models\DimensionValue;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Supplier;
use Inertia\Testing\AssertableInertia as Assert;

const DIMENSION_KEEPER = [
    'finance.accounts.view', 'finance.accounts.manage', 'finance.journals.view', 'finance.journals.post',
    'finance.payables.view', 'finance.payables.manage', 'finance.reports.view',
];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
    $this->actingAsMember('acme', DIMENSION_KEEPER);
});

function dimUrl(string $path): string
{
    return test()->workspaceUrl('acme', $path);
}

function dimAccount(string $code): Account
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole());
}

function dimValue(string $kind, string $code, string $name = 'Value'): DimensionValue
{
    test()->post(dimUrl('dimensions'), ['kind' => $kind, 'code' => $code, 'name' => $name])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => DimensionValue::query()->where('kind', $kind)->where('code', strtoupper($code))->sole());
}

/**
 * @param  array{cost_centre_id?: string, project_id?: string}  $dimensions  on the first (debit) line
 */
function dimEntry(string $date, string $debit, string $credit, string $amount, array $dimensions = [], string $memo = 'Entry')
{
    return test()->post(dimUrl('journals'), [
        'entry_date' => $date,
        'memo' => $memo,
        'lines' => [
            ['account_id' => dimAccount($debit)->id, 'debit' => $amount] + $dimensions,
            ['account_id' => dimAccount($credit)->id, 'credit' => $amount],
        ],
    ]);
}

function requireOn(string $code, bool $costCentre, bool $project = false): void
{
    $account = dimAccount($code);
    test()->put(dimUrl("accounts/{$account->id}"), [
        'code' => $account->code, 'name' => $account->name, 'type' => $account->type->value, 'active' => '1',
        'requires_cost_centre' => $costCentre ? '1' : '0', 'requires_project' => $project ? '1' : '0',
    ])->assertSessionHasNoErrors();
}

it('keeps codes unique per kind and closes values instead of deleting them', function () {
    $admin = dimValue(DimensionValue::COST_CENTRE, 'adm', 'Administration');
    expect($admin->code)->toBe('ADM')->and($admin->label())->toBe('ADM · Administration');

    $this->post(dimUrl('dimensions'), ['kind' => 'cost_centre', 'code' => 'ADM', 'name' => 'Again'])->assertSessionHasErrors('code');
    dimValue(DimensionValue::PROJECT, 'ADM', 'A project may share the code');

    $this->put(dimUrl("dimensions/{$admin->id}"), ['code' => 'ADM', 'name' => 'Admin', 'active' => '0'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $admin->refresh()->active))->toBeFalse();

    // A closed value is not offered, and not accepted, on new lines.
    dimEntry('2026-10-01', '6000', '1010', '10', ['cost_centre_id' => $admin->id])->assertSessionHasErrors('lines.0.cost_centre_id');
    $this->get(dimUrl('journals/create'))->assertInertia(fn (Assert $page) => $page->where('dimensions.costCentres', []));
});

it('requires the dimensions an account asks for on entries made by hand, and keeps them on reversal', function () {
    $clinic = dimValue(DimensionValue::COST_CENTRE, 'CLN', 'Clinic');
    $grant = dimValue(DimensionValue::PROJECT, 'GR1', 'Grant 1');
    requireOn('6000', costCentre: true, project: true);

    dimEntry('2026-10-01', '6000', '1010', '100')->assertSessionHasErrors(['lines.0' => '6000 Rent needs a cost centre.']);
    dimEntry('2026-10-01', '6000', '1010', '100', ['cost_centre_id' => $clinic->id])->assertSessionHasErrors(['lines.0' => '6000 Rent needs a project.']);
    dimEntry('2026-10-01', '6000', '1010', '100', ['cost_centre_id' => $grant->id, 'project_id' => $grant->id])->assertSessionHasErrors('lines.0.cost_centre_id');
    dimEntry('2026-10-01', '6000', '1010', '100', ['cost_centre_id' => $clinic->id, 'project_id' => $grant->id], 'Rent')->assertSessionHasNoErrors();

    $journal = $this->asTenant('acme', fn () => Journal::query()->where('memo', 'Rent')->sole());
    $this->get(dimUrl("journals/{$journal->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('journal.lines.0.costCentre', 'CLN · Clinic')
        ->where('journal.lines.0.project', 'GR1 · Grant 1')
        ->where('journal.lines.1.costCentre', null));

    // The reversal takes the same line off the same cost centre and project.
    $this->post(dimUrl("journals/{$journal->id}/reverse"), ['entry_date' => '2026-10-02', 'reason' => 'Wrong month'])->assertSessionHasNoErrors();
    $this->asTenant('acme', function () use ($clinic, $grant) {
        $reversed = JournalLine::query()->where('account_id', Account::query()->where('code', '6000')->sole()->id)->where('credit_minor', 10000)->sole();
        expect($reversed->cost_centre_id)->toBe($clinic->id)->and($reversed->project_id)->toBe($grant->id);
    });
});

it('requires them on bills too, but never refuses a product event', function () {
    $clinic = dimValue(DimensionValue::COST_CENTRE, 'CLN', 'Clinic');
    requireOn('6200', costCentre: true);
    requireOn('4000', costCentre: true);
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Power company']));
    $bill = fn (array $dimensions) => $this->post(dimUrl('bills'), [
        'supplier_id' => $supplier->id, 'bill_date' => '2026-10-01',
        'lines' => [['account_id' => dimAccount('6200')->id, 'description' => 'Electricity', 'amount' => '120'] + $dimensions],
    ]);

    $bill([])->assertSessionHasErrors('lines.0');
    $bill(['cost_centre_id' => $clinic->id])->assertSessionHasNoErrors();

    // A sale from a product posts to sales revenue with no cost centre.
    $this->sendEvent('finance.invoice.issued', 'sale:S-1:invoice', [
        'invoice_number' => 'S-1', 'branch_id' => null, 'customer_reference' => null, 'currency' => 'SSP',
        'total_minor' => 5000, 'tax_minor' => 0, 'due_on' => null,
        'lines' => [['description' => 'Goods', 'quantity' => 1, 'amount_minor' => 5000, 'revenue_account' => null]],
    ], occurredAt: '2026-10-02T09:00:00+00:00')->assertCreated();
    expect($this->asTenant('acme', fn () => Journal::query()->where('source_reference', 'S-1')->exists()))->toBeTrue();
});

it('splits profit and loss by cost centre into columns that add up to the whole', function () {
    $clinic = dimValue(DimensionValue::COST_CENTRE, 'CLN', 'Clinic');
    $admin = dimValue(DimensionValue::COST_CENTRE, 'ADM', 'Administration');
    dimEntry('2026-10-01', '1010', '4900', '1000', memo: 'Income, no cost centre');
    dimEntry('2026-10-02', '6000', '1010', '300', ['cost_centre_id' => $clinic->id]);
    dimEntry('2026-10-03', '6000', '1010', '200', ['cost_centre_id' => $admin->id]);
    dimEntry('2026-10-04', '6200', '1010', '50', ['cost_centre_id' => $clinic->id]);
    dimEntry('2026-10-05', '6900', '1010', '25');

    $this->get(dimUrl('reports/profit-and-loss-by-dimension?from=2026-10-01&to=2026-10-31&by=cost_centre'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/profit-and-loss-by-dimension')
            ->where('columns', [
                ['id' => $admin->id, 'label' => 'ADM · Administration'],
                ['id' => $clinic->id, 'label' => 'CLN · Clinic'],
                ['id' => 'none', 'label' => 'No cost centre'],
            ])
            ->where("profit.{$clinic->id}", -35000)
            ->where("profit.{$admin->id}", -20000)
            ->where('profit.none', 100000 - 2500)
            ->where("expenseTotals.{$clinic->id}", 35000)
            ->where('profitMinor', 100000 - 57500));

    // Each column is the profit and loss filtered to it.
    $this->get(dimUrl("reports/profit-and-loss?from=2026-10-01&to=2026-10-31&cost_centre={$clinic->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('profitMinor', -35000)->where('costCentre', $clinic->id)->has('expenses', 2));
    $this->get(dimUrl('reports/profit-and-loss?from=2026-10-01&to=2026-10-31&cost_centre=none'))
        ->assertInertia(fn (Assert $page) => $page->where('profitMinor', 97500)->where('revenueMinor', 100000));
    $this->get(dimUrl('reports/profit-and-loss?from=2026-10-01&to=2026-10-31&cost_centre=01a1a1a1-0000-7000-8000-000000000001'))
        ->assertSessionHasErrors('cost_centre');
    $this->get(dimUrl('reports/profit-and-loss?from=2026-10-01&to=2026-10-31&project=clinic'))
        ->assertSessionHasErrors('project');

    // The trial balance filters the same way (and need not balance).
    $this->get(dimUrl("reports/trial-balance?as_of=2026-10-31&cost_centre={$clinic->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('debitMinor', 35000)->where('creditMinor', 0));

    $csv = $this->get(dimUrl('reports/profit-and-loss-by-dimension?from=2026-10-01&to=2026-10-31&by=cost_centre&export=statement'))->assertOk()->streamedContent();
    expect($csv)->toContain('Section,Code,Account,"ADM · Administration","CLN · Clinic","No cost centre",Total')
        ->toContain('Expenses,6000,Rent,200.00,300.00,0.00,500.00');
});

it('keeps managing cost centres and projects to those who manage accounts', function () {
    $this->actingAsMember('acme', ['finance.accounts.view']);
    $this->get(dimUrl('dimensions'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('dimensions/index'));
    $this->post(dimUrl('dimensions'), ['kind' => 'project', 'code' => 'X', 'name' => 'X'])->assertForbidden();
});
