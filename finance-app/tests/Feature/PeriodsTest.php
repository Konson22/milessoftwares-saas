<?php

use App\Finance\Statements;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Journal;
use Carbon\CarbonImmutable;

const CLOSER = [
    'finance.accounts.view', 'finance.journals.view', 'finance.journals.post', 'finance.reports.view',
    'finance.periods.manage',
];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
});

function books(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path);
}

function ledgerAccount(string $code): Account
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole());
}

function month(string $startsOn): FiscalPeriod
{
    return test()->asTenant('acme', fn () => FiscalPeriod::query()->whereDate('starts_on', $startsOn)->sole());
}

function fiscalYear(string $name): FiscalYear
{
    return test()->asTenant('acme', fn () => FiscalYear::query()->where('name', $name)->sole());
}

/** A manual journal: the response of posting it. */
function journal(string $date, string $debitCode, string $creditCode, string $amount, string $memo = 'Entry')
{
    return test()->post(books('journals'), [
        'entry_date' => $date,
        'memo' => $memo,
        'lines' => [
            ['account_id' => ledgerAccount($debitCode)->id, 'debit' => $amount],
            ['account_id' => ledgerAccount($creditCode)->id, 'credit' => $amount],
        ],
    ]);
}

function lockThrough(string $lastMonthStart): void
{
    $months = test()->asTenant('acme', fn () => FiscalPeriod::query()->whereDate('starts_on', '<=', $lastMonthStart)->orderBy('starts_on')->get());
    foreach ($months as $period) {
        test()->post(books("periods/{$period->id}/lock"))->assertSessionHasNoErrors();
    }
}

it('sets up years one after the other, split into months', function () {
    $this->actingAsMember('acme', CLOSER);

    $this->post(books('periods/years'), ['starts_on' => '2026-01-15', 'months' => 12])->assertSessionHasErrors('starts_on');
    $this->post(books('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12])->assertSessionHasNoErrors();
    // The next year follows on; a start date sent with it is ignored.
    $this->post(books('periods/years'), ['starts_on' => '2030-01-01', 'months' => 6])->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $years = FiscalYear::query()->orderBy('starts_on')->with('periods')->get();

        expect($years->pluck('name')->all())->toBe(['FY 2026', 'FY 2027'])
            ->and($years[0]->periods)->toHaveCount(12)
            ->and($years[0]->periods->last()->ends_on->toDateString())->toBe('2026-12-31')
            ->and($years[1]->starts_on->toDateString())->toBe('2027-01-01')
            ->and($years[1]->ends_on->toDateString())->toBe('2027-06-30')
            ->and($years[1]->periods)->toHaveCount(6);
    });
});

it('locks months in order and refuses manual entries on or before the locked day', function () {
    $this->actingAsMember('acme', CLOSER);
    $this->post(books('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12]);

    $this->post(books('periods/'.month('2026-02-01')->id.'/lock'))->assertSessionHasErrors('period');
    lockThrough('2026-02-01');

    journal('2026-02-28', '6000', '1000', '100')->assertSessionHasErrors('entry_date');
    journal('2025-12-31', '6000', '1000', '100')->assertSessionHasErrors('entry_date');
    journal('2026-03-01', '6000', '1000', '100')->assertSessionHasNoErrors();

    // Unlocked from the latest only.
    $this->post(books('periods/'.month('2026-01-01')->id.'/unlock'))->assertSessionHasErrors('period');
    $this->post(books('periods/'.month('2026-02-01')->id.'/unlock'))->assertSessionHasNoErrors();
    journal('2026-02-28', '6000', '1000', '100')->assertSessionHasNoErrors();

    $this->get(books('periods'))->assertOk();
});

it('does not limit entry dates before any year is set up', function () {
    $this->actingAsMember('acme', CLOSER);

    journal('2019-05-01', '6000', '1000', '100')->assertSessionHasNoErrors();
});

it('posts a product event dated in a locked month on the first open day', function () {
    $this->actingAsMember('acme', CLOSER);
    $this->post(books('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12]);
    lockThrough('2026-01-01');

    $this->sendEvent('finance.invoice.issued', 'sale:S-1:invoice', [
        'invoice_number' => 'S-1',
        'branch_id' => null,
        'customer_reference' => 'Walk-in',
        'currency' => 'SSP',
        'total_minor' => 5000,
        'tax_minor' => 0,
        'due_on' => null,
        'lines' => [['description' => 'Late sale', 'quantity' => 1, 'amount_minor' => 5000, 'revenue_account' => null]],
    ], occurredAt: '2026-01-20T10:00:00+00:00')->assertCreated();

    $this->asTenant('acme', function () {
        $journal = Journal::query()->sole();

        expect($journal->entry_date->toDateString())->toBe('2026-02-01')
            ->and($journal->memo)->toContain('dated 20 Jan 2026, books locked');
    });
});

it('closes a year into retained earnings without changing its profit and loss, and reopens it', function () {
    $this->actingAsMember('acme', CLOSER);
    $this->post(books('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12]);
    $this->post(books('periods/years'), ['months' => 12]);

    journal('2026-03-10', '1010', '4000', '1000', 'Sales');
    journal('2026-04-10', '6000', '1010', '300', 'Rent');
    journal('2027-01-10', '1010', '4900', '50', 'Interest');

    // Years close in order.
    $this->post(books('periods/years/'.fiscalYear('FY 2027')->id.'/close'))->assertSessionHasErrors('year');
    $this->post(books('periods/years/'.fiscalYear('FY 2026')->id.'/close'))->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $statements = app(Statements::class);
        $year = FiscalYear::query()->where('name', 'FY 2026')->sole();

        expect($year->closed())->toBeTrue()
            ->and($year->periods()->whereNull('locked_at')->count())->toBe(0)
            ->and($statements->profitAndLoss(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'))['profitMinor'])->toBe(70000);

        $sheet = $statements->balanceSheet(CarbonImmutable::parse('2027-01-31'));
        $retained = collect($sheet['equity'])->firstWhere('code', '3100');
        expect($retained['amountMinor'])->toBe(70000)
            ->and($sheet['earningsMinor'])->toBe(5000)
            ->and($sheet['assetsMinor'])->toBe($sheet['liabilitiesMinor'] + $sheet['equityMinor']);

        $trial = $statements->trialBalance(CarbonImmutable::parse('2026-12-31'));
        expect(collect($trial['rows'])->pluck('code')->all())->toBe(['1010', '3100']);
    });

    // A closed year's months cannot be unlocked or posted into.
    $this->post(books('periods/'.month('2026-12-01')->id.'/unlock'))->assertSessionHasErrors('period');
    journal('2026-12-31', '6000', '1010', '10')->assertSessionHasErrors('entry_date');

    $this->post(books('periods/years/'.fiscalYear('FY 2026')->id.'/reopen'))->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $sheet = app(Statements::class)->balanceSheet(CarbonImmutable::parse('2027-01-31'));

        expect(collect($sheet['equity'])->firstWhere('code', '3100'))->toBeNull()
            ->and($sheet['earningsMinor'])->toBe(75000)
            ->and(FiscalYear::query()->where('name', 'FY 2026')->sole()->closed())->toBeFalse()
            ->and(Journal::query()->where('source_type', Journal::YEAR_REOPEN)->count())->toBe(1);
    });

    // Its months stay locked until unlocked.
    $this->post(books('periods/'.month('2026-12-01')->id.'/unlock'))->assertSessionHasNoErrors();
});

it('closes a year that had no revenue or expenses without a journal', function () {
    $this->actingAsMember('acme', CLOSER);
    $this->post(books('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12]);

    $this->post(books('periods/years/'.fiscalYear('FY 2026')->id.'/close'))->assertSessionHasNoErrors();

    expect(fiscalYear('FY 2026')->closing_journal_id)->toBeNull()
        ->and(fiscalYear('FY 2026')->closed())->toBeTrue();
});

it('posts opening balances against opening balance equity, and replaces them', function () {
    $this->actingAsMember('acme', CLOSER);

    $this->put(books('opening-balances'), ['balances' => [
        ['account_id' => ledgerAccount('1010')->id, 'debit' => '100'],
    ]])->assertSessionHasErrors('balances');

    $this->post(books('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12]);
    $this->get(books('opening-balances'))->assertOk();

    $this->put(books('opening-balances'), ['balances' => [
        ['account_id' => ledgerAccount('1010')->id, 'debit' => '1000'],
        ['account_id' => ledgerAccount('1400')->id, 'debit' => '400'],
        ['account_id' => ledgerAccount('2500')->id, 'credit' => '600'],
    ]])->assertSessionHasNoErrors();

    $this->put(books('opening-balances'), ['balances' => [
        ['account_id' => ledgerAccount('1010')->id, 'debit' => '1200'],
        ['account_id' => ledgerAccount('2500')->id, 'credit' => '600'],
    ]])->assertSessionHasNoErrors();

    $this->put(books('opening-balances'), ['balances' => [
        ['account_id' => ledgerAccount('4000')->id, 'credit' => '10'],
    ]])->assertSessionHasErrors();

    $this->asTenant('acme', function () {
        $trial = collect(app(Statements::class)->trialBalance(CarbonImmutable::parse('2026-01-01'))['rows'])->keyBy('code');

        expect($trial->pluck('code')->values()->all())->toBe(['1010', '2500', '3900'])
            ->and($trial['1010']['debitMinor'])->toBe(120000)
            ->and($trial['2500']['creditMinor'])->toBe(60000)
            ->and($trial['3900']['creditMinor'])->toBe(60000)
            ->and(Journal::query()->where('source_type', Journal::OPENING_BALANCES)->whereNull('reversed_by_id')->count())->toBe(1)
            ->and(Journal::query()->whereNotNull('reversal_of_id')->sole()->entry_date->toDateString())->toBe('2026-01-01');
    });

    // Not once the first month is locked.
    lockThrough('2026-01-01');
    $this->put(books('opening-balances'), ['balances' => [
        ['account_id' => ledgerAccount('1010')->id, 'debit' => '1300'],
    ]])->assertSessionHasErrors('entry_date');
});

it('lets only members who manage periods lock, close or enter opening balances', function () {
    $this->actingAsMember('acme', CLOSER);
    $this->post(books('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12]);

    $this->actingAsMember('acme', ['finance.journals.view', 'finance.journals.post']);

    $this->get(books('periods'))->assertOk();
    $this->post(books('periods/years'), ['months' => 12])->assertForbidden();
    $this->post(books('periods/'.month('2026-01-01')->id.'/lock'))->assertForbidden();
    $this->post(books('periods/years/'.fiscalYear('FY 2026')->id.'/close'))->assertForbidden();
    $this->get(books('opening-balances'))->assertForbidden();
});
