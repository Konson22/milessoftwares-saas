<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events', 'pharmacy.max_discount_percent' => 20]);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Panadol', 'sell_price_minor' => 250]));
    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($keeper) {
        app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2026-12-31'), 3, 90, $keeper);
        app(Stock::class)->add($this->para->item, $this->juba, 'P-2', CarbonImmutable::parse('2027-12-31'), 20, 100, $keeper);
    });
});

/**
 * Signs in someone who sells here, with their till open.
 */
function seller(array $permissions, string $branch): void
{
    test()->openTill(test()->actingAsMember('acme', $permissions, branch: $branch), $branch);
}

function counterSale(array $lines, array $extra = [])
{
    return test()->post(test()->workspaceUrl('acme', 'sales'), [
        'items' => $lines,
        'payment_method' => 'cash',
        'amount_tendered' => '100.00',
        ...$extra,
    ]);
}

describe('submitting a sale twice', function () {
    it('records one sale for one basket, however often it is sent', function () {
        seller(['pharmacy.sales.create', 'pharmacy.sales.view'], $this->juba);
        $key = (string) Str::uuid();

        $first = counterSale([['item_id' => $this->para->id, 'quantity' => 4]], ['submission_id' => $key]);
        $second = counterSale([['item_id' => $this->para->id, 'quantity' => 4]], ['submission_id' => $key]);

        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
        $first->assertRedirect($this->workspaceUrl('acme', "sales/{$sale->id}"));
        $second->assertRedirect($this->workspaceUrl('acme', "sales/{$sale->id}"));

        expect((int) $this->asTenant('acme', fn () => StockMovement::query()->where('reference_id', $sale->id)->sum('quantity')))->toBe(-4)
            ->and(DB::table('platform_outbox')->where('event_type', 'finance.invoice.issued')->count())->toBe(1);
    });

    it('records separate sales for separate baskets', function () {
        seller(['pharmacy.sales.create'], $this->juba);

        counterSale([['item_id' => $this->para->id, 'quantity' => 1]], ['submission_id' => (string) Str::uuid()]);
        counterSale([['item_id' => $this->para->id, 'quantity' => 1]], ['submission_id' => (string) Str::uuid()]);
        counterSale([['item_id' => $this->para->id, 'quantity' => 1]]);

        expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(3);
    });
});

describe('discounts', function () {
    it('takes a discount off the line, shares it across batches, and books the net amount in Finance', function () {
        seller(['pharmacy.sales.create', 'pharmacy.sales.discount'], $this->juba);

        // 5 × 2.50 = 12.50; 10% off = 1.25, from batches of 3 and 2.
        counterSale([['item_id' => $this->para->id, 'quantity' => 5, 'discount_percent' => 10]])->assertSessionHasNoErrors();

        $sale = $this->asTenant('acme', fn () => Sale::query()->with('lines')->sole());
        expect($sale->total_minor)->toBe(1125)
            ->and($sale->discount_minor)->toBe(125)
            ->and($sale->change_minor)->toBe(10_000 - 1125)
            ->and($sale->lines->map(fn ($line) => [$line->quantity, $line->discount_minor, $line->line_total_minor])->all())->toBe([[3, 75, 675], [2, 50, 450]]);

        $invoice = Envelope::fromArray($this->platform()->moduleEvents['finance'][0]);
        expect($invoice->payload['total_minor'])->toBe(1125)
            ->and($invoice->payload['lines'][0]['amount_minor'])->toBe(1125)
            ->and($invoice->payload['lines'][0]['cost_minor'])->toBe(3 * 90 + 2 * 100);
    });

    it('refuses more than the allowed discount, and any discount without the permission', function (array $permissions, int $percent, string $message) {
        seller(['pharmacy.sales.create', ...$permissions], $this->juba);

        counterSale([['item_id' => $this->para->id, 'quantity' => 1, 'discount_percent' => $percent]])
            ->assertSessionHasErrors(['items.0.discount_percent' => $message]);

        expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
    })->with([
        'above the limit' => [['pharmacy.sales.discount'], 25, 'You may give at most 20% off.'],
        'no permission' => [[], 5, 'You may not give discounts.'],
    ]);

    it('tells the counter how much this member may take off', function () {
        seller(['pharmacy.sales.create', 'pharmacy.sales.discount'], $this->juba);
        $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page->where('maxDiscountPercent', 20));

        seller(['pharmacy.sales.create'], $this->juba);
        $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page->where('maxDiscountPercent', 0));
    });
});

describe('receipts', function () {
    it('prints one line per medicine, with the discount and the change', function () {
        seller(['pharmacy.sales.create', 'pharmacy.sales.view', 'pharmacy.sales.discount'], $this->juba);
        counterSale([['item_id' => $this->para->id, 'quantity' => 5, 'discount_percent' => 10]]);
        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());

        $this->get($this->workspaceUrl('acme', "sales/{$sale->id}/receipt"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/receipt')
                ->has('sale.lines', 1)
                ->where('sale.lines.0.quantity', 5)
                ->where('sale.lines.0.discountMinor', 125)
                ->where('sale.lines.0.lineTotalMinor', 1125)
                ->where('sale.changeMinor', 8875));
    });

    it('never prints another branch\'s receipt', function () {
        seller(['pharmacy.sales.create'], $this->juba);
        counterSale([['item_id' => $this->para->id, 'quantity' => 1]]);
        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());

        $this->actingAsMember('acme', ['pharmacy.sales.view'], branch: $this->wau);
        $this->get($this->workspaceUrl('acme', "sales/{$sale->id}/receipt"))->assertNotFound();
    });
});
