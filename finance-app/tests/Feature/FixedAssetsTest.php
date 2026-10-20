<?php

use App\Finance\FixedAssets;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Bill;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\Journal;
use App\Models\Supplier;
use Inertia\Testing\AssertableInertia as Assert;

const ASSET_KEEPER = [
    'finance.accounts.view', 'finance.accounts.manage', 'finance.journals.view', 'finance.journals.post',
    'finance.payables.view', 'finance.payables.manage', 'finance.reports.view', 'finance.assets.manage',
];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
    $this->actingAsMember('acme', ASSET_KEEPER, features: [FixedAssets::FEATURE]);
});

function faUrl(string $path): string
{
    return test()->workspaceUrl('acme', $path);
}

function faAccount(string $code): Account
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole());
}

function faBalance(string $code): int
{
    return test()->asTenant('acme', fn () => (int) faAccount($code)->lines()->selectRaw('COALESCE(SUM(debit_minor - credit_minor), 0) AS balance')->value('balance'));
}

function faCategory(string $name, string $method, int $life, ?string $rate = null): AssetCategory
{
    test()->post(faUrl('asset-categories'), [
        'name' => $name, 'asset_account_id' => faAccount('1500')->id, 'accumulated_account_id' => faAccount('1590')->id,
        'expense_account_id' => faAccount('6400')->id, 'method' => $method, 'life_months' => $life, 'rate' => $rate,
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => AssetCategory::query()->where('name', $name)->sole());
}

/** A manual journal putting the amount in equipment, paid from the bank. */
function faPurchase(string $date, string $amount, string $memo): Journal
{
    test()->post(faUrl('journals'), ['entry_date' => $date, 'memo' => $memo, 'lines' => [
        ['account_id' => faAccount('1500')->id, 'debit' => $amount],
        ['account_id' => faAccount('1010')->id, 'credit' => $amount],
    ]])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Journal::query()->where('memo', $memo)->sole());
}

function faRegister(array $fields)
{
    return test()->post(faUrl('fixed-assets'), $fields + ['source' => 'journal']);
}

function faAsset(string $name): FixedAsset
{
    return test()->asTenant('acme', fn () => FixedAsset::query()->where('name', $name)->sole());
}

function faRun(string $month)
{
    return test()->post(faUrl('depreciation'), ['month' => $month]);
}

function faRegisterMatchesLedger(): void
{
    test()->get(faUrl('fixed-assets'))->assertInertia(fn (Assert $page) => $page->where('reconciliation', function ($rows) {
        foreach ($rows as $row) {
            expect($row['ledgerMinor'])->toBe($row['registerMinor'], $row['account']);
        }

        return count($rows) === 2;
    }));
}

it('is not found without the plan feature', function () {
    $this->actingAsMember('acme', ASSET_KEEPER);

    $this->get(faUrl('fixed-assets'))->assertNotFound();
    $this->get(faUrl('depreciation'))->assertNotFound();
    $this->post(faUrl('asset-categories'), ['name' => 'X'])->assertNotFound();
});

it('registers assets against the bill or journal that bought them, never more than it put in', function () {
    $vehicles = faCategory('Vehicles', AssetCategory::STRAIGHT_LINE, 36);
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Motors']));
    $this->post(faUrl('bills'), [
        'supplier_id' => $supplier->id, 'bill_date' => '2026-01-15',
        'lines' => [['account_id' => faAccount('1500')->id, 'description' => 'Pickup', 'amount' => '36000']],
    ])->assertSessionHasNoErrors();
    $bill = $this->asTenant('acme', fn () => Bill::query()->sole());

    $this->get(faUrl('fixed-assets'))->assertInertia(fn (Assert $page) => $page->has('sources.bills', 1));
    faRegister(['asset_category_id' => $vehicles->id, 'name' => 'Pickup', 'acquired_on' => '2026-01-15', 'cost' => '36000', 'source' => 'bill', 'bill_id' => $bill->id])
        ->assertRedirect();
    faRegister(['asset_category_id' => $vehicles->id, 'name' => 'Again', 'acquired_on' => '2026-01-15', 'cost' => '1', 'source' => 'bill', 'bill_id' => $bill->id])
        ->assertSessionHasErrors('bill_id');

    $pickup = faAsset('Pickup');
    expect($pickup->number)->toBe('FA-0001')
        ->and($pickup->life_months)->toBe(36)
        ->and($pickup->acquisition_journal_id)->toBe($bill->journal_id);

    $journal = faPurchase('2026-01-20', '500', 'Spare parts');
    faRegister(['asset_category_id' => $vehicles->id, 'name' => 'Big', 'acquired_on' => '2026-01-20', 'cost' => '600', 'journal_id' => $journal->id])->assertSessionHasErrors('journal_id');
    faRegister(['asset_category_id' => $vehicles->id, 'name' => 'Residual', 'acquired_on' => '2026-01-20', 'cost' => '500', 'residual' => '500', 'journal_id' => $journal->id])->assertSessionHasErrors('residual');
    faRegister(['asset_category_id' => $vehicles->id, 'name' => 'Engine', 'acquired_on' => '2026-01-20', 'cost' => '500', 'journal_id' => $journal->id])->assertRedirect();

    faRegisterMatchesLedger();
});

it('runs depreciation month by month, once, never below the residual value', function () {
    $vehicles = faCategory('Vehicles', AssetCategory::STRAIGHT_LINE, 36);
    $tools = faCategory('Tools', AssetCategory::STRAIGHT_LINE, 3);
    $computers = faCategory('Computers', AssetCategory::DECLINING_BALANCE, 36, '40');
    $this->post(faUrl('asset-categories'), [
        'name' => 'Bad', 'asset_account_id' => faAccount('1500')->id, 'accumulated_account_id' => faAccount('1590')->id,
        'expense_account_id' => faAccount('6400')->id, 'method' => AssetCategory::DECLINING_BALANCE, 'life_months' => 12,
    ])->assertSessionHasErrors('rate');

    $truck = faPurchase('2026-01-15', '36000', 'Truck');
    $drill = faPurchase('2026-01-05', '100', 'Drill');
    faRegister(['asset_category_id' => $vehicles->id, 'name' => 'Truck', 'acquired_on' => '2026-01-15', 'cost' => '36000', 'journal_id' => $truck->id])->assertRedirect();
    faRegister(['asset_category_id' => $tools->id, 'name' => 'Drill', 'acquired_on' => '2026-01-05', 'cost' => '100', 'residual' => '10', 'journal_id' => $drill->id])->assertRedirect();

    $this->get(faUrl('depreciation'))->assertInertia(fn (Assert $page) => $page
        ->where('next', '2026-01')
        ->where('runnable', true)
        ->where('preview.0.amountMinor', 100000)
        ->where('preview.1.amountMinor', 3000));

    faRun('2026-01')->assertSessionHasNoErrors();
    faRun('2026-01')->assertSessionHasErrors('month');
    faRun('2026-03')->assertSessionHasErrors('month'); // February first
    faRun('2099-01')->assertSessionHasErrors('month');
    faRun('2026-02')->assertSessionHasNoErrors();

    // Bought in February, registered after its run: March catches up.
    $laptop = faPurchase('2026-02-10', '1000', 'Laptop');
    faRegister(['asset_category_id' => $computers->id, 'name' => 'Laptop', 'acquired_on' => '2026-02-10', 'cost' => '1000', 'journal_id' => $laptop->id])->assertRedirect();
    faRun('2026-03')->assertSessionHasNoErrors();
    faRun('2026-04')->assertSessionHasNoErrors();
    faRun('2026-05')->assertSessionHasNoErrors();

    // 40% a year on the declining value: 3,333 then 3,222 (both in March), …
    expect($this->asTenant('acme', fn () => faAsset('Laptop')->depreciation()->join('depreciation_runs', 'depreciation_runs.id', '=', 'depreciation_lines.depreciation_run_id')->orderBy('depreciation_runs.month')->pluck('amount_minor')->all()))
        ->toBe([6555, 3115, 3011])
        // Three months of the drill's life, then nothing: it stops at its residual value.
        ->and($this->asTenant('acme', fn () => faAsset('Drill')->accumulated()))->toBe(9000)
        ->and($this->asTenant('acme', fn () => faAsset('Truck')->accumulated()))->toBe(500000)
        ->and(-faBalance('1590'))->toBe(500000 + 9000 + 6555 + 3115 + 3011)
        ->and(faBalance('6400'))->toBe(500000 + 9000 + 6555 + 3115 + 3011)
        ->and($this->asTenant('acme', fn () => DepreciationRun::query()->count()))->toBe(5);

    $this->get(faUrl('fixed-assets/'.faAsset('Drill')->id))->assertInertia(fn (Assert $page) => $page
        ->component('fixed-assets/show')
        ->has('schedule', 3)
        ->where('schedule.2.valueMinor', 1000)
        ->where('schedule.2.posted', true));

    faRegisterMatchesLedger();
});

it('undoes only the latest run, and reruns it the same', function () {
    $vehicles = faCategory('Vehicles', AssetCategory::STRAIGHT_LINE, 36);
    $truck = faPurchase('2026-01-15', '36000', 'Truck');
    faRegister(['asset_category_id' => $vehicles->id, 'name' => 'Truck', 'acquired_on' => '2026-01-15', 'cost' => '36000', 'journal_id' => $truck->id])->assertRedirect();
    faRun('2026-01')->assertSessionHasNoErrors();
    faRun('2026-02')->assertSessionHasNoErrors();
    [$january, $february] = $this->asTenant('acme', fn () => DepreciationRun::query()->orderBy('month')->get()->all());

    $this->delete(faUrl("depreciation/{$january->id}"))->assertSessionHasErrors('run');
    $this->delete(faUrl("depreciation/{$february->id}"))->assertSessionHasNoErrors();
    expect(-faBalance('1590'))->toBe(100000)
        ->and($this->asTenant('acme', fn () => Journal::query()->where('source_type', Journal::REVERSAL)->count()))->toBe(1);

    faRun('2026-02')->assertSessionHasNoErrors();
    expect(-faBalance('1590'))->toBe(200000);
    faRegisterMatchesLedger();
});

it('disposes of an asset with the gain or loss, and stops depreciating it', function () {
    $vehicles = faCategory('Vehicles', AssetCategory::STRAIGHT_LINE, 36);
    $truck = faPurchase('2026-01-15', '36000', 'Truck');
    $van = faPurchase('2026-01-15', '1200', 'Van');
    faRegister(['asset_category_id' => $vehicles->id, 'name' => 'Truck', 'acquired_on' => '2026-01-15', 'cost' => '36000', 'journal_id' => $truck->id])->assertRedirect();
    faRegister(['asset_category_id' => $vehicles->id, 'name' => 'Van', 'acquired_on' => '2026-01-15', 'cost' => '1200', 'journal_id' => $van->id])->assertRedirect();
    foreach (['2026-01', '2026-02', '2026-03', '2026-04'] as $month) {
        faRun($month)->assertSessionHasNoErrors();
    }
    $truck = faAsset('Truck');
    $april = $this->asTenant('acme', fn () => DepreciationRun::query()->orderByDesc('month')->first());

    $dispose = fn (FixedAsset $asset, array $fields) => $this->post(faUrl("fixed-assets/{$asset->id}/dispose"), $fields);
    $dispose($truck, ['disposed_on' => '2026-03-15', 'proceeds' => '33000', 'account_id' => faAccount('1010')->id])->assertSessionHasErrors('disposed_on');
    $dispose($truck, ['disposed_on' => '2026-04-20', 'proceeds' => '33000'])->assertSessionHasErrors('account_id');

    // Value 36,000 − 4,000 = 32,000; sold for 33,000: a gain of 1,000.
    $bank = faBalance('1010');
    $dispose($truck, ['disposed_on' => '2026-04-20', 'proceeds' => '33000', 'account_id' => faAccount('1010')->id])->assertSessionHasNoErrors();
    expect(faBalance('6960'))->toBe(-100000)
        ->and(faBalance('1010') - $bank)->toBe(3300000)
        ->and(faBalance('1500'))->toBe(120000)
        ->and($this->asTenant('acme', fn () => $truck->refresh()->disposed()))->toBeTrue();
    $dispose($truck, ['disposed_on' => '2026-04-21'])->assertSessionHasErrors('disposed_on');
    $this->delete(faUrl("depreciation/{$april->id}"))->assertSessionHasErrors('run');

    // Written off with nothing received: the remaining value is a loss.
    faRun('2026-05')->assertSessionHasNoErrors();
    $this->get(faUrl('depreciation'))->assertInertia(fn (Assert $page) => $page->where('runs.0.assets', 1));
    $dispose(faAsset('Van'), ['disposed_on' => '2026-05-31'])->assertSessionHasNoErrors();
    expect(faBalance('6960'))->toBe(-100000 + (120000 - 16667)) // five months: 1,200 × 5 / 36
        ->and(faBalance('1500'))->toBe(0)
        ->and(faBalance('1590'))->toBe(0);

    faRegisterMatchesLedger();
});

it('keeps registering, running and disposing to those who manage assets', function () {
    $this->actingAsMember('acme', ['finance.accounts.view'], features: [FixedAssets::FEATURE]);

    $this->get(faUrl('fixed-assets'))->assertOk();
    $this->get(faUrl('asset-categories'))->assertOk();
    $this->get(faUrl('depreciation'))->assertInertia(fn (Assert $page) => $page->component('depreciation/index')->where('next', null));
    $this->post(faUrl('asset-categories'), ['name' => 'X'])->assertForbidden();
    $this->post(faUrl('fixed-assets'), ['name' => 'X'])->assertForbidden();
    $this->post(faUrl('depreciation'), ['month' => '2026-01'])->assertForbidden();
});
