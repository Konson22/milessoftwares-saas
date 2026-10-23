<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\TillSession;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['sell_price_minor' => 250]));
    $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 100, 100, User::factory()->create()));
});

function till(string $number = 'T-00001'): TillSession
{
    return test()->asTenant('acme', fn () => TillSession::query()->where('number', $number)->sole());
}

function sellAtCounter(int $quantity, string $method = 'cash'): void
{
    test()->post(test()->workspaceUrl('acme', 'sales'), array_filter([
        'items' => [['item_id' => test()->para->id, 'quantity' => $quantity]],
        'payment_method' => $method,
        'amount_tendered' => $method === 'cash' ? '100.00' : null,
    ]))->assertSessionHasNoErrors();
}

it('sells nothing until the cashier opens a till', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page->where('till', null));
    $this->post($this->workspaceUrl('acme', 'sales'), [
        'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
        'payment_method' => 'cash',
        'amount_tendered' => '5.00',
    ])->assertSessionHasErrors('till');

    expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);

    $this->post($this->workspaceUrl('acme', 'tills'), ['opening_float' => '20.00'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', 'tills'), ['opening_float' => '20.00'])->assertSessionHasErrors('opening_float');

    expect(till())->status->toBe(TillSession::OPEN)->opening_float_minor->toBe(2000);
    $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page->where('till.number', 'T-00001'));
});

it('works out what the till should hold and closes it on the count', function () {
    $cashier = $this->actingAsMember('acme', ['pharmacy.sales.create', 'pharmacy.sales.void'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', 'tills'), ['opening_float' => '10.00']);

    sellAtCounter(4);                    // 10.00 cash
    sellAtCounter(2, 'mobile_money');    // 5.00 mobile money
    sellAtCounter(1);                    // 2.50 cash, voided below
    $voided = $this->asTenant('acme', fn () => Sale::query()->where('number', 'S-000003')->sole());
    $this->post($this->workspaceUrl('acme', "sales/{$voided->id}/void"), ['reason' => 'Wrong item'])->assertSessionHasNoErrors();

    // 10.00 float + 12.50 cash taken − 2.50 given back.
    $this->get($this->workspaceUrl('acme', 'tills/'.till()->id))->assertInertia(fn (Assert $page) => $page
        ->component('tills/show')
        ->where('summary.salesCount', 3)
        ->where('summary.takenMinor.cash', 1250)
        ->where('summary.takenMinor.mobile_money', 500)
        ->where('summary.cashRefundsMinor', 250)
        ->where('summary.expectedCashMinor', 2000));

    $this->post($this->workspaceUrl('acme', 'tills/'.till()->id.'/close'), ['counted_cash' => '19.00'])->assertSessionHasErrors('closing_note');
    expect(till()->status)->toBe(TillSession::OPEN);

    $this->post($this->workspaceUrl('acme', 'tills/'.till()->id.'/close'), ['counted_cash' => '19.00', 'closing_note' => 'Gave a customer 1.00 too much'])->assertSessionHasNoErrors();

    expect(till())
        ->status->toBe(TillSession::CLOSED)
        ->closed_by->toBe($cashier->getAuthIdentifier())
        ->expected_cash_minor->toBe(2000)
        ->counted_cash_minor->toBe(1900)
        ->and(till()->difference())->toBe(-100);

    $this->post($this->workspaceUrl('acme', 'tills/'.till()->id.'/close'), ['counted_cash' => '20.00'])->assertSessionHasErrors('counted_cash');

    // A closed till takes no more sales.
    $this->post($this->workspaceUrl('acme', 'sales'), [
        'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
        'payment_method' => 'cash',
        'amount_tendered' => '5.00',
    ])->assertSessionHasErrors('till');
});

it('closes without a note when the count is right, and keeps the figures fixed after', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', 'tills'), ['opening_float' => '5.00']);
    sellAtCounter(2);

    $this->post($this->workspaceUrl('acme', 'tills/'.till()->id.'/close'), ['counted_cash' => '10.00'])->assertSessionHasNoErrors();

    // A sale voided later (from another till) does not rewrite the count.
    $manager = $this->actingAsMember('acme', ['pharmacy.sales.void', 'pharmacy.sales.view'], branch: $this->juba);
    $this->openTill($manager, $this->juba);
    $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
    $this->post($this->workspaceUrl('acme', "sales/{$sale->id}/void"), ['reason' => 'Returned'])->assertSessionHasNoErrors();

    expect(till())->expected_cash_minor->toBe(1000)->counted_cash_minor->toBe(1000)
        ->and($sale->refresh()->void_till_session_id)->toBe(till('T-00002')->id);
});

it('counts cash put in and taken out in what the till should hold, and never more out than it holds', function () {
    $cashier = $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', 'tills'), ['opening_float' => '10.00']);
    sellAtCounter(4);                    // 10.00 cash
    $url = $this->workspaceUrl('acme', 'tills/'.till()->id.'/movements');

    $this->post($url, ['direction' => 'in', 'amount' => '5.00', 'reason' => 'Change from the safe'])->assertSessionHasNoErrors();
    $this->post($url, ['direction' => 'out', 'amount' => '12.00', 'reason' => 'Drop to the safe'])->assertSessionHasNoErrors();

    // 10.00 float + 10.00 cash taken + 5.00 in − 12.00 out.
    $this->post($url, ['direction' => 'out', 'amount' => '13.01', 'reason' => 'Drop'])->assertSessionHasErrors('amount');
    $this->post($url, ['direction' => 'out', 'amount' => '0', 'reason' => 'Drop'])->assertSessionHasErrors('amount');
    $this->post($url, ['direction' => 'out', 'amount' => '1.00'])->assertSessionHasErrors('reason');
    $this->post($url, ['direction' => 'sideways', 'amount' => '1.00', 'reason' => 'x'])->assertSessionHasErrors('direction');

    $this->get($this->workspaceUrl('acme', 'tills/'.till()->id))->assertInertia(fn (Assert $page) => $page
        ->where('summary.cashInMinor', 500)
        ->where('summary.cashOutMinor', 1200)
        ->where('summary.movementsCount', 2)
        ->where('summary.expectedCashMinor', 1300)
        ->where('canMove', true)
        ->has('movements', 2)
        ->where('movements.0.direction', 'in')
        ->where('movements.1.reason', 'Drop to the safe')
        ->where('movements.1.recordedBy', $cashier->name));

    $this->post($this->workspaceUrl('acme', 'tills/'.till()->id.'/close'), ['counted_cash' => '13.00'])->assertSessionHasNoErrors();
    expect(till())->expected_cash_minor->toBe(1300);

    // A closed till takes no more cash in or out.
    $this->post($url, ['direction' => 'in', 'amount' => '1.00', 'reason' => 'Late'])->assertSessionHasErrors('amount');
    $this->get($this->workspaceUrl('acme', 'tills/'.till()->id))->assertInertia(fn (Assert $page) => $page->where('canMove', false));
});

it('lets a manager move cash in a cashier\'s till, and nobody else', function () {
    $cashier = $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);
    $this->openTill($cashier, $this->juba);
    $url = $this->workspaceUrl('acme', 'tills/'.till()->id.'/movements');

    $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);
    $this->post($url, ['direction' => 'in', 'amount' => '1.00', 'reason' => 'Change'])->assertForbidden();

    $this->actingAsMember('acme', ['pharmacy.tills.manage'], branch: $this->wau);
    $this->post($url, ['direction' => 'in', 'amount' => '1.00', 'reason' => 'Change'])->assertNotFound();

    $this->actingAsMember('acme', ['pharmacy.tills.manage'], branch: $this->juba);
    $this->post($url, ['direction' => 'in', 'amount' => '1.00', 'reason' => 'Change'])->assertSessionHasNoErrors();

    expect(till()->cashMovements()->sole())->amount_minor->toBe(100);
});

it('gives cash back only from an open till', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', 'tills'), ['opening_float' => '0']);
    sellAtCounter(1);
    $sale = $this->asTenant('acme', fn () => Sale::query()->sole());

    $this->actingAsMember('acme', ['pharmacy.sales.void'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', "sales/{$sale->id}/void"), ['reason' => 'No till'])->assertSessionHasErrors('reason');

    expect($sale->refresh()->status)->toBe(Sale::COMPLETED);
});

it('shows cashiers their own tills and managers everyone\'s, in this branch only', function () {
    $first = $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);
    $this->openTill($first, $this->juba);
    $second = $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);
    $this->openTill($second, $this->juba);

    $this->get($this->workspaceUrl('acme', 'tills'))->assertInertia(fn (Assert $page) => $page
        ->where('managing', false)
        ->has('tills', 1)
        ->where('mine.number', 'T-00002'));
    $this->get($this->workspaceUrl('acme', 'tills/'.till()->id))->assertForbidden();
    $this->post($this->workspaceUrl('acme', 'tills/'.till()->id.'/close'), ['counted_cash' => '0'])->assertForbidden();

    $this->actingAsMember('acme', ['pharmacy.tills.manage'], branch: $this->juba);
    $this->get($this->workspaceUrl('acme', 'tills'))->assertInertia(fn (Assert $page) => $page->where('managing', true)->has('tills', 2));
    $this->post($this->workspaceUrl('acme', 'tills/'.till()->id.'/close'), ['counted_cash' => '0'])->assertSessionHasNoErrors();
    expect(till()->status)->toBe(TillSession::CLOSED);

    $this->actingAsMember('acme', ['pharmacy.tills.manage'], branch: $this->wau);
    $this->get($this->workspaceUrl('acme', 'tills/'.till('T-00002')->id))->assertNotFound();
});

/**
 * The Juba counter's shift settings.
 *
 * @param  array<string, mixed>  $settings
 */
function shiftSettings(array $settings): void
{
    test()->asTenant('acme', fn () => BranchCounterSettings::query()->updateOrCreate(
        ['branch_id' => test()->juba, 'application' => 'pharmacy'],
        $settings,
    ));
}

describe('branch shift settings', function () {
    it('sells without a shift where the branch does not need one', function () {
        shiftSettings(['shifts_required' => false]);
        $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);

        $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page
            ->where('till', null)
            ->where('counter.shiftsRequired', false));
        sellAtCounter(1);

        expect($this->asTenant('acme', fn () => Sale::query()->sole()->till_session_id))->toBeNull();
    });

    it('prefills the usual starting cash', function () {
        shiftSettings(['default_float_minor' => 5000]);
        $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);

        $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page->where('counter.defaultFloat', '50.00'));
        $this->get($this->workspaceUrl('acme', 'tills'))->assertInertia(fn (Assert $page) => $page
            ->where('shifts.required', true)
            ->where('shifts.defaultFloat', '50.00'));
    });

    it('lets cashiers count blind, and shows the manager the difference', function () {
        shiftSettings(['blind_close' => true]);
        $cashier = $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', 'tills'), ['opening_float' => '10.00']);
        sellAtCounter(4);

        $this->get($this->workspaceUrl('acme', 'tills/'.till()->id))->assertInertia(fn (Assert $page) => $page
            ->where('shifts.blind', true)
            ->where('summary.expectedCashMinor', null)
            ->where('summary.cashRefundsMinor', null)
            ->where('summary.takenMinor', [])
            ->where('canClose', true));

        // A short count needs no reason when counted blind.
        $this->post($this->workspaceUrl('acme', 'tills/'.till()->id.'/close'), ['counted_cash' => '19.00'])->assertSessionHasNoErrors();
        expect(till())->status->toBe(TillSession::CLOSED)
            ->and(till()->difference())->toBe(-100);
        $this->get($this->workspaceUrl('acme', 'tills'))->assertInertia(fn (Assert $page) => $page
            ->where('tills.0.countedCashMinor', 1900)
            ->where('tills.0.expectedCashMinor', null)
            ->where('tills.0.differenceMinor', null));

        $this->actingAsMember('acme', ['pharmacy.tills.manage'], branch: $this->juba);
        $this->get($this->workspaceUrl('acme', 'tills/'.till()->id))->assertInertia(fn (Assert $page) => $page
            ->where('shifts.blind', false)
            ->where('till.expectedCashMinor', 2000)
            ->where('till.differenceMinor', -100));
    });

    it('leaves closing to managers where the branch says so', function () {
        shiftSettings(['manager_closes_shifts' => true]);
        $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', 'tills'), ['opening_float' => '0']);

        $this->get($this->workspaceUrl('acme', 'tills/'.till()->id))->assertInertia(fn (Assert $page) => $page->where('canClose', false));
        $this->post($this->workspaceUrl('acme', 'tills/'.till()->id.'/close'), ['counted_cash' => '0'])->assertForbidden();
        expect(till()->status)->toBe(TillSession::OPEN);

        $this->actingAsMember('acme', ['pharmacy.tills.manage'], branch: $this->juba);
        $this->get($this->workspaceUrl('acme', 'tills/'.till()->id))->assertInertia(fn (Assert $page) => $page->where('canClose', true));
        $this->post($this->workspaceUrl('acme', 'tills/'.till()->id.'/close'), ['counted_cash' => '0'])->assertSessionHasNoErrors();
        expect(till()->status)->toBe(TillSession::CLOSED);
    });

    it('is saved with the counter settings', function () {
        $this->actingAsMember('acme', ['pharmacy.sales.settings'], branch: $this->juba);

        $this->post($this->workspaceUrl('acme', 'counter-settings'), [
            'workflow' => 'direct',
            'paper_size' => '80mm',
            'shifts_required' => '0',
            'default_float' => '25.00',
            'blind_close' => '1',
            'manager_closes_shifts' => '1',
        ])->assertSessionHasNoErrors();

        $settings = $this->asTenant('acme', fn () => BranchCounterSettings::forBranch($this->juba, 'pharmacy'));
        expect($settings->shifts_required)->toBeFalse()
            ->and($settings->default_float_minor)->toBe(2500)
            ->and($settings->blind_close)->toBeTrue()
            ->and($settings->manager_closes_shifts)->toBeTrue();

        $this->get($this->workspaceUrl('acme', 'counter-settings'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.shiftsRequired', false)
            ->where('settings.defaultFloat', '25.00'));
    });
});
