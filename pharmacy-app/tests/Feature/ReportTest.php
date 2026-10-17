<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Actions\Sell;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Actions\VoidSale;
use App\Modules\Sales\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->malakal = $this->platform()->branch('acme', 'Malakal');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->asTenant('acme', function () {
        $keeper = User::factory()->create();
        app(Tills::class)->open($keeper, $this->juba, 0);
        app(Tills::class)->open($keeper, $this->malakal, 0);
        $this->para = Medicine::factory()->create(['sell_price_minor' => 250]);
        $sell = fn (string $branch, int $quantity, PaymentMethod $method) => app(Sell::class)->handle($keeper, $branch, [['item_id' => $this->para->id, 'quantity' => $quantity]], $method, tenderedMinor: $method === PaymentMethod::Cash ? 10_000 : null);

        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00'));
        app(Stock::class)->add($this->para->item, $this->juba, 'E-1', CarbonImmutable::parse('2026-10-01'), 5, 80, $keeper);
        app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-03-31'), 40, 100, $keeper);
        app(Stock::class)->add($this->para->item, $this->malakal, 'M-1', CarbonImmutable::parse('2027-03-31'), 10, 100, $keeper);

        // September, outside the default period: from E-1, which expires first.
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00'));
        $sell($this->juba, 1, PaymentMethod::Cash);

        // October: E-1 has expired, so from P-1.
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00'));
        $sell($this->juba, 4, PaymentMethod::Cash);

        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
        $sell($this->juba, 2, PaymentMethod::MobileMoney);
        app(VoidSale::class)->handle($sell($this->juba, 1, PaymentMethod::Cash), $keeper, 'Wrong medicine');
        $sell($this->malakal, 3, PaymentMethod::Cash);
    });
});

it('reports the month so far: takings, profit, payments, days and best sellers', function () {
    $this->actingAsMember('acme', ['pharmacy.reports.view'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme', 'reports'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')
            ->where('from', '2026-10-01')
            ->where('to', '2026-10-12')
            ->where('summary', [
                'count' => 2,
                'revenueMinor' => 1500,
                'costMinor' => 600,
                'profitMinor' => 900,
                'averageMinor' => 750,
                'voidedCount' => 1,
                'voidedMinor' => 250,
                'returnedCount' => 0,
                'returnedMinor' => 0,
            ])
            ->where('byMethod', [
                ['method' => 'Cash', 'count' => 1, 'totalMinor' => 1000],
                ['method' => 'Mobile money', 'count' => 1, 'totalMinor' => 500],
            ])
            ->where('byDay', [
                ['day' => '2026-10-01', 'count' => 1, 'totalMinor' => 1000],
                ['day' => '2026-10-12', 'count' => 1, 'totalMinor' => 500],
            ])
            ->where('topMedicines', [
                ['id' => $this->para->id, 'medicine' => $this->para->label(), 'quantity' => 6, 'revenueMinor' => 1500, 'costMinor' => 600],
            ])
            // P-1: 40 − 4 − 2 (the voided one came back); E-1's 4 expired.
            ->where('stock', [
                'units' => 34,
                'costMinor' => 3400,
                'retailMinor' => 8500,
                'expiredUnits' => 4,
                'expiredCostMinor' => 320,
            ]));
});

it('reports a chosen period at the cost of the batches actually sold', function () {
    $this->actingAsMember('acme', ['pharmacy.reports.view'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme', 'reports?from=2026-09-01&to=2026-09-30'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.count', 1)
            ->where('summary.revenueMinor', 250)
            ->where('summary.costMinor', 80));
});

it('cuts a period longer than a year to its last year', function () {
    $this->actingAsMember('acme', ['pharmacy.reports.view'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme', 'reports?from=2020-01-01&to=2026-10-12'))
        ->assertInertia(fn (Assert $page) => $page->where('from', '2025-10-12')->where('to', '2026-10-12'));
});

it('refuses a period that ends before it starts', function () {
    $this->actingAsMember('acme', ['pharmacy.reports.view'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme', 'reports?from=2026-10-12&to=2026-10-01'))
        ->assertSessionHasErrors('to');
});

it('reports only the branch being worked in, and only to members who may view reports', function () {
    $this->actingAsMember('acme', ['pharmacy.reports.view'], branch: $this->malakal);

    $this->get($this->workspaceUrl('acme', 'reports'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.count', 1)
            ->where('summary.revenueMinor', 750)
            ->where('stock.units', 7));

    $this->actingAsMember('acme', ['pharmacy.sales.view', 'pharmacy.stock.view'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme', 'reports'))->assertForbidden();
});
