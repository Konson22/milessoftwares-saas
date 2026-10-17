<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Procurement\Actions\ReceiveGoods;
use App\Modules\Procurement\Actions\ReturnGoods;
use App\Modules\Sales\Actions\ReturnSale;
use App\Modules\Sales\Actions\Sell;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->asTenant('acme', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00'));
        $keeper = User::factory()->create();
        $this->cashier = User::factory()->create(['name' => 'Achol']);
        $this->para = Medicine::factory()->create(['sell_price_minor' => 250]);
        $this->amox = Medicine::factory()->create(['sell_price_minor' => 600]);
        $juba = Supplier::query()->create(['name' => 'Juba Medical']);
        $nile = Supplier::query()->create(['name' => 'Nile Pharma']);
        $receive = app(ReceiveGoods::class);

        // Before the period: not in the purchases.
        $receive->handle($keeper, $this->juba, $juba, null, null, CarbonImmutable::parse('2026-09-20'), [
            ['item_id' => $this->amox->id, 'batch_number' => 'A-0', 'expires_on' => '2028-01-31', 'quantity' => 10, 'unit_cost_minor' => 280],
        ]);
        $first = $receive->handle($keeper, $this->juba, $juba, null, 'INV-77', CarbonImmutable::parse('2026-10-02'), [
            ['item_id' => $this->para->id, 'batch_number' => 'P-1', 'expires_on' => '2027-06-30', 'quantity' => 50, 'unit_cost_minor' => 100],
            ['item_id' => $this->amox->id, 'batch_number' => 'A-1', 'expires_on' => '2027-06-30', 'quantity' => 20, 'unit_cost_minor' => 300],
        ]);
        $receive->handle($keeper, $this->juba, $nile, null, null, CarbonImmutable::parse('2026-10-05'), [
            ['item_id' => $this->para->id, 'batch_number' => 'P-2', 'expires_on' => '2027-09-30', 'quantity' => 30, 'unit_cost_minor' => 120],
        ]);
        app(ReturnGoods::class)->handle($keeper, $first, [$first->lines()->where('batch_number', 'P-1')->value('id') => 5], 'Short-dated', CarbonImmutable::parse('2026-10-06'));

        // Achol's till: 10.00 float.
        $till = app(Tills::class)->open($this->cashier, $this->juba, 1000);
        $sell = app(Sell::class);
        // 10 × 2.50 less 10%: 22.50 cash, from P-1 at 1.00.
        $sale = $sell->handle($this->cashier, $this->juba, [['item_id' => $this->para->id, 'quantity' => 10, 'discount_percent' => 10]], PaymentMethod::Cash, tenderedMinor: 2500, maxDiscountPercent: 20);
        // 2 × 6.00 by mobile money, from A-1 at 3.00.
        $sell->handle($this->cashier, $this->juba, [['item_id' => $this->amox->id, 'quantity' => 2]], PaymentMethod::MobileMoney);
        // 2 paracetamol back on the shelf: 4.50 in cash.
        app(ReturnSale::class)->handle($sale, $this->cashier, [['sale_line_id' => $sale->lines()->value('id'), 'quantity' => 2, 'restock' => true]], 'Not needed');

        // Expected 10.00 + 22.50 − 4.50 = 28.00; counted 27.00.
        app(Tills::class)->close($till, $this->cashier, 2700, 'One note missing');
    });

    $this->actingAsMember('acme', ['pharmacy.reports.view'], branch: $this->juba);
});

it('shows what each medicine earned, and agrees with the overview', function () {
    $this->get($this->workspaceUrl('acme', 'reports/profitability'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/profitability')
        ->where('from', '2026-10-01')
        ->where('rows.0.id', $this->para->id)
        ->where('rows.0.quantity', 8)
        ->where('rows.0.returnedQuantity', 2)
        ->where('rows.0.discountMinor', 250)
        ->where('rows.0.revenueMinor', 1800)
        ->where('rows.0.costMinor', 800)
        ->where('rows.0.profitMinor', 1000)
        ->where('rows.1.id', $this->amox->id)
        ->where('rows.1.profitMinor', 600)
        ->where('totals', ['revenueMinor' => 3000, 'costMinor' => 1400, 'profitMinor' => 1600, 'discountMinor' => 250]));

    $this->get($this->workspaceUrl('acme', 'reports'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.revenueMinor', 3000)
        ->where('summary.costMinor', 1400)
        ->where('summary.profitMinor', 1600));
});

it('shows what was bought, per supplier and per medicine, in the period', function () {
    $this->get($this->workspaceUrl('acme', 'reports/purchases'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/purchases')
        ->where('bySupplier', fn (Collection $rows) => $rows->map(fn ($row) => [$row['supplier'], $row['receipts'], $row['receivedMinor'], $row['returnedMinor'], $row['netMinor']])->all() === [
            ['Juba Medical', 1, 11000, 500, 10500],
            ['Nile Pharma', 1, 3600, 0, 3600],
        ])
        ->where('byItem.0.id', $this->para->id)
        ->where('byItem.0.quantity', 80)
        ->where('byItem.0.totalMinor', 8600)
        ->where('byItem.0.averageCostMinor', 107)
        ->where('byItem.0.lowestCostMinor', 100)
        ->where('byItem.0.highestCostMinor', 120)
        ->where('byItem.1.totalMinor', 6000)
        ->has('receipts', 2)
        ->where('receipts.1.supplierReference', 'INV-77')
        ->where('totals', ['receivedMinor' => 14600, 'returnedMinor' => 500, 'netMinor' => 14100]));

    $this->get($this->workspaceUrl('acme', 'reports/purchases?from=2026-09-01&to=2026-09-30'))->assertInertia(fn (Assert $page) => $page
        ->where('totals.receivedMinor', 2800));
});

it('reconciles each cashier: takings, refunds, and how the count came out', function () {
    $this->get($this->workspaceUrl('acme', 'reports/cashiers'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/cashiers')
        ->has('byCashier', 1)
        ->where('byCashier.0.cashier', 'Achol')
        ->where('byCashier.0.salesCount', 2)
        ->where('byCashier.0.takenMinor', 3450)
        ->where('byCashier.0.cashRefundsMinor', 450)
        ->where('byCashier.0.overMinor', 0)
        ->where('byCashier.0.shortMinor', 100)
        ->where('tills.0.expectedCashMinor', 2800)
        ->where('tills.0.countedCashMinor', 2700)
        ->where('tills.0.differenceMinor', -100)
        ->where('tills.0.closingNote', 'One note missing'));
});

it('keeps the analysis reports to members who may see reports', function (string $path) {
    $this->actingAsMember('acme', ['pharmacy.sales.view'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme', $path))->assertForbidden();
})->with(['reports/profitability', 'reports/purchases', 'reports/cashiers']);

it('downloads each report table as CSV, amounts in major units', function () {
    $csv = fn (string $path) => $this->get($this->workspaceUrl('acme', $path))->assertOk()->streamedContent();

    $tills = $csv('reports/cashiers?from=2026-10-01&to=2026-10-31&export=tills');
    $lines = explode("\n", trim(ltrim($tills, "\u{FEFF}")));
    expect($lines[0])->toStartWith('Till,Cashier,Opened')
        ->and($lines)->toHaveCount(2)
        ->and($lines[1])->toContain('Achol')->toContain(',28.00,27.00,-1.00,"One note missing"');

    $this->get($this->workspaceUrl('acme', 'reports/profitability?from=2026-10-01&to=2026-10-31&export=medicines'))
        ->assertHeader('content-disposition', 'attachment; filename=profitability-2026-10-01-to-2026-10-31.csv');
    expect($csv('reports/purchases?from=2026-10-01&to=2026-10-31&export=deliveries'))->toContain('INV-77')
        ->and($csv('reports?from=2026-10-01&to=2026-10-31&export=methods'))->toContain('Cash,1,22.50');

    $this->get($this->workspaceUrl('acme', 'reports/purchases?export=nonsense'))->assertNotFound();
});

it('keeps formulas in CSV cells as text', function () {
    $this->asTenant('acme', fn () => Supplier::query()->where('name', 'Nile Pharma')->update(['name' => '=HYPERLINK("x")']));

    expect($this->get($this->workspaceUrl('acme', 'reports/purchases?from=2026-10-01&to=2026-10-31&export=suppliers'))->streamedContent())
        ->toContain('"\'=HYPERLINK(""x"")"');
});
