<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Retail\Models\Product;
use App\Modules\Sales\Actions\ReturnSale;
use App\Modules\Sales\Actions\Sell;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Retail R5 (docs/erp/retail-roadmap.md): reports. The shop's takings by
| category and cashier, margins, what to reorder and what is not moving,
| each downloadable as CSV. A branch that runs the pharmacy as well reports
| each industry on its own, and the shop's takings are what it posted to
| Finance: invoices less returns.
*/

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->asTenant('acme', function () {
        $keeper = User::factory()->create();
        $this->soap = Product::factory()->create(['name' => 'Soap', 'category' => 'Household', 'sell_price_minor' => 300, 'reorder_level' => 5]);
        $this->rice = Product::factory()->create(['name' => 'Rice 5kg', 'category' => 'Food', 'sell_price_minor' => 1000, 'reorder_level' => 10]);
        $this->candles = Product::factory()->create(['name' => 'Candles', 'category' => null, 'sell_price_minor' => 150, 'reorder_level' => 5]);
        $this->para = Medicine::factory()->create(['name' => 'Panadol', 'sell_price_minor' => 250, 'reorder_level' => 50]);

        app(Stock::class)->add($this->soap, $this->juba, 'S-1', null, 100, 200, $keeper);
        app(Stock::class)->add($this->rice, $this->juba, 'R-1', null, 5, 700, $keeper);
        app(Stock::class)->add($this->candles, $this->juba, 'C-1', null, 50, 100, $keeper);
        app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 10, 90, $keeper);

        // The pharmacy counter at the same branch: never in the shop's figures.
        app(Tills::class)->open($keeper, $this->juba, 0);
        app(Sell::class)->handle($keeper, $this->juba, [['item_id' => $this->para->id, 'quantity' => 2]], PaymentMethod::Cash, tenderedMinor: 1000);
    });
});

function reportUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path, 'retail');
}

/**
 * Rings up a cash sale at the shop counter as this cashier, and returns it.
 *
 * @param  list<array{0: Product, 1: int}>  $lines
 */
function ringUp(User $cashier, array $lines): Sale
{
    test()->actingAsMember('acme', ['retail.sales.create'], branch: test()->juba, application: 'retail', user: $cashier);
    test()->post(reportUrl('sales'), [
        'submission_id' => (string) Str::uuid(),
        'items' => array_map(fn (array $line) => ['item_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        'payment_method' => 'cash',
        'amount_tendered' => '100.00',
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Sale::query()->where('application', 'retail')->latest('sold_at')->latest('number')->firstOrFail());
}

/**
 * Achol sells 4 soap and 2 rice, Deng 1 soap; 1 of Achol's soap comes back
 * onto the shelf.
 */
function aDayAtTheShop(): void
{
    $achol = test()->asTenant('acme', fn () => User::factory()->create(['name' => 'Achol']));
    $deng = test()->asTenant('acme', fn () => User::factory()->create(['name' => 'Deng']));
    test()->openTill($achol, test()->juba);
    test()->openTill($deng, test()->juba);

    $sale = ringUp($achol, [[test()->soap, 4], [test()->rice, 2]]);
    ringUp($deng, [[test()->soap, 1]]);

    $manager = test()->actingAsMember('acme', ['retail.sales.void', 'retail.sales.view'], branch: test()->juba, application: 'retail');
    test()->openTill($manager, test()->juba);
    $soapLine = test()->asTenant('acme', fn () => $sale->lines()->where('item_id', test()->soap->id)->sole());
    test()->post(reportUrl("sales/{$sale->id}/returns"), [
        'reason' => 'Damaged wrapper',
        'lines' => [['sale_line_id' => $soapLine->id, 'quantity' => 1, 'restock' => true]],
    ])->assertSessionHasNoErrors();
}

function shopReporter(): User
{
    $user = test()->actingAsMember('acme', ['retail.reports.view'], branch: test()->juba, application: 'retail');
    assert($user instanceof User);

    return $user;
}

describe('access', function () {
    it('opens the reports only to members who may see them, here and not at the pharmacy', function () {
        $this->actingAsMember('acme', ['retail.sales.view', 'retail.stock.view'], branch: $this->juba, application: 'retail');
        foreach (['reports', 'reports/sales', 'reports/profitability', 'reports/stock', 'reports/purchases', 'reports/cashiers'] as $path) {
            $this->get(reportUrl($path))->assertForbidden();
        }

        $this->actingAsMember('acme', ['pharmacy.reports.view'], branch: $this->juba);
        $this->get(reportUrl('reports'))->assertForbidden();

        $reporter = shopReporter();
        $this->get(reportUrl('reports'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('retail/reports/index'));
        $this->actingAsMember('acme', ['retail.reports.view'], branch: $this->juba, application: 'retail', user: $reporter);
        $this->get($this->workspaceUrl('acme', 'reports'))->assertForbidden();
    });
});

describe('takings', function () {
    it('reports only the shop: the pharmacy sale at the same branch stays out', function () {
        aDayAtTheShop();
        shopReporter();

        $this->get(reportUrl('reports'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/reports/index')
            ->where('summary.count', 2)
            ->where('summary.revenueMinor', 3200)
            ->where('summary.costMinor', 2200)
            ->where('summary.profitMinor', 1000)
            ->where('summary.returnedCount', 1)
            ->where('summary.returnedMinor', 300)
            ->has('topMedicines', 2)
            // Soap 100 − 5 + 1, rice 5 − 2, candles 50; no Panadol.
            ->where('stock.units', 149)
            ->where('stock.costMinor', 96 * 200 + 3 * 700 + 50 * 100));
    });

    it('cuts the takings by category and by cashier, both adding up to the overview', function () {
        aDayAtTheShop();
        shopReporter();

        $this->get(reportUrl('reports/sales'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/reports/sales')
            ->where('byCategory', [
                ['id' => 'Food', 'name' => 'Food', 'sales' => 1, 'quantity' => 2, 'returnedQuantity' => 0, 'discountMinor' => 0, 'revenueMinor' => 2000, 'costMinor' => 1400, 'profitMinor' => 600, 'marginPercent' => 30],
                ['id' => 'Household', 'name' => 'Household', 'sales' => 2, 'quantity' => 4, 'returnedQuantity' => 1, 'discountMinor' => 0, 'revenueMinor' => 1200, 'costMinor' => 800, 'profitMinor' => 400, 'marginPercent' => 33.3],
            ])
            ->where('byCashier.0.name', 'Achol')
            ->where('byCashier.0.revenueMinor', 2900)
            ->where('byCashier.0.costMinor', 2000)
            ->where('byCashier.0.quantity', 5)
            ->where('byCashier.0.returnedQuantity', 1)
            ->where('byCashier.1.name', 'Deng')
            ->where('byCashier.1.revenueMinor', 300)
            ->where('byCashier.1.profitMinor', 100)
            ->where('totals', ['revenueMinor' => 3200, 'costMinor' => 2200, 'profitMinor' => 1000, 'discountMinor' => 0]));
    });

    it('gives margins per product that agree with the overview', function () {
        aDayAtTheShop();
        shopReporter();

        $this->get(reportUrl('reports/profitability'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/reports/profitability')
            ->has('rows', 2)
            ->where('rows.0.medicine', 'Rice 5kg')
            ->where('rows.0.category', 'Food')
            ->where('rows.1.medicine', 'Soap')
            ->where('rows.1.quantity', 4)
            ->where('rows.1.profitMinor', 400)
            ->where('totals.revenueMinor', 3200)
            ->where('totals.costMinor', 2200));
    });

    it('takes what it posted to Finance: invoices less returns', function () {
        aDayAtTheShop();
        shopReporter();

        $events = fn (string $type) => DB::table('platform_outbox')->where('application', 'retail')->where('event_type', $type)->get()
            ->map(fn ($row) => json_decode($row->payload, true));
        $invoiced = $events('finance.invoice.issued')->sum('total_minor');
        $returned = $events('finance.sales_return.issued')->sum('total_minor');
        $cost = $events('finance.invoice.issued')->flatMap(fn (array $invoice) => $invoice['lines'])->sum('cost_minor');

        expect($invoiced - $returned)->toBe(3200)
            ->and($cost)->toBe(2400);

        $this->get(reportUrl('reports/sales'))->assertInertia(fn (Assert $page) => $page
            ->where('totals.revenueMinor', $invoiced - $returned));
    });

    it('keeps the pharmacy returns out of the shop cuts', function () {
        aDayAtTheShop();
        $this->asTenant('acme', function () {
            $sale = Sale::query()->where('application', 'pharmacy')->sole();
            app(ReturnSale::class)->handle($sale, User::query()->findOrFail($sale->sold_by), [
                ['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => 1, 'restock' => true],
            ], 'Wrong strength');
        });
        shopReporter();

        $this->get(reportUrl('reports/sales'))->assertInertia(fn (Assert $page) => $page
            ->has('byCashier', 2)
            ->where('totals.revenueMinor', 3200)
            ->where('totals.costMinor', 2200));
    });

    it('leaves the shop out of the pharmacy reports', function () {
        aDayAtTheShop();
        $this->actingAsMember('acme', ['pharmacy.reports.view'], branch: $this->juba);

        $this->get($this->workspaceUrl('acme', 'reports'))->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')
            ->where('summary.count', 1)
            ->where('summary.revenueMinor', 500)
            ->where('summary.returnedCount', 0)
            ->where('stock.units', 8));
        $this->get($this->workspaceUrl('acme', 'reports/profitability'))->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.medicine', $this->para->label()));
    });

    it('downloads the cuts as CSV', function () {
        aDayAtTheShop();
        shopReporter();

        $csv = $this->get(reportUrl('reports/sales?export=categories'))->assertOk()->streamedContent();
        expect($csv)->toContain('Category,Sales,"Quantity sold","Quantity returned",Discounts,Revenue,Cost,Profit,"Margin %"')
            ->toContain('Food,1,2,0,0.00,20.00,14.00,6.00,30')
            ->toContain('Household,2,4,1,0.00,12.00,8.00,4.00,33.3');

        $csv = $this->get(reportUrl('reports/sales?export=cashiers'))->assertOk()->streamedContent();
        expect($csv)->toContain('Achol,1,5,1,0.00,29.00,20.00,9.00,31');

        $this->get(reportUrl('reports/sales?export=nothing'))->assertNotFound();
    });
});

describe('purchases', function () {
    it('reports the deliveries the shop received, not the pharmacy ones', function () {
        $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Juba Wholesale']));
        $this->actingAsMember('acme', ['pharmacy.purchasing.receive'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', 'receipts'), [
            'supplier_id' => $supplier->id, 'supplier_reference' => 'INV-1', 'received_on' => '2026-10-19',
            'lines' => [['item_id' => $this->para->id, 'batch_number' => 'P-2', 'expires_on' => '2027-12-31', 'quantity' => 10, 'unit_cost' => '1.00']],
        ])->assertSessionHasNoErrors();
        $this->actingAsMember('acme', ['retail.purchasing.receive'], branch: $this->juba, application: 'retail');
        $this->post(reportUrl('receipts'), [
            'supplier_id' => $supplier->id, 'supplier_reference' => 'DN-7', 'received_on' => '2026-10-19',
            'lines' => [['item_id' => $this->soap->id, 'batch_number' => 'S-2', 'quantity' => 24, 'unit_cost' => '1.50']],
        ])->assertSessionHasNoErrors();
        shopReporter();

        $this->get(reportUrl('reports/purchases'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/reports/purchases')
            ->has('receipts', 1)
            ->where('receipts.0.supplierReference', 'DN-7')
            ->has('byItem', 1)
            ->where('byItem.0.medicine', 'Soap')
            ->where('totals.receivedMinor', 3600));
    });
});

describe('stock', function () {
    it('lists what to reorder and what is not moving', function () {
        aDayAtTheShop();
        shopReporter();

        // The period is the month so far, 19 days. Soap: 96 on hand and 5
        // sold would last 364 days; candles sold nothing.
        $this->get(reportUrl('reports/stock'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/reports/stock')
            ->has('reorder', 1)
            ->where('reorder.0.name', 'Rice 5kg')
            ->where('reorder.0.onHand', 3)
            ->where('reorder.0.shortBy', 7)
            ->where('reorder.0.soldUnits', 2)
            ->where('reorder.0.lastCostMinor', 700)
            ->has('slow', 2)
            ->where('slow.0.name', 'Soap')
            ->where('slow.0.stockCostMinor', 19200)
            ->where('slow.0.coverDays', 364)
            ->where('slow.0.lastSoldOn', '2026-10-19')
            ->where('slow.1.name', 'Candles')
            ->where('slow.1.stockCostMinor', 5000)
            ->where('slow.1.coverDays', null)
            ->where('slow.1.lastSoldOn', null)
            ->where('totals', ['reorderCount' => 1, 'outOfStock' => 0, 'slowCount' => 2, 'slowCostMinor' => 24200]));
    });

    it('measures the pace over the period asked for', function () {
        aDayAtTheShop();
        shopReporter();

        // Today alone: 5 soap a day lasts 19 days, so only candles are slow;
        // rice at 2 a day is out of the shelf in a day and a half.
        $this->get(reportUrl('reports/stock?from=2026-10-19&to=2026-10-19'))->assertInertia(fn (Assert $page) => $page
            ->where('slow', fn ($rows) => collect($rows)->pluck('name')->all() === ['Candles'])
            ->where('reorder.0.perDay', 2)
            ->where('reorder.0.coverDays', 1));
    });

    it('downloads the reorder list and slow movers as CSV', function () {
        aDayAtTheShop();
        shopReporter();

        expect($this->get(reportUrl('reports/stock?export=reorder'))->assertOk()->streamedContent())
            ->toContain('"Rice 5kg",Food,piece,3,10,7,2,0.11,7.00');
        expect($this->get(reportUrl('reports/stock?export=slow'))->assertOk()->streamedContent())
            ->toContain('Candles,,piece,50,50.00,0,,');
    });
});
