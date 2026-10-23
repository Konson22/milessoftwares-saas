<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Retail\Models\Product;
use App\Modules\Sales\Models\Promotion;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Promotions: a percentage off a product or a category, for some days, in
| every branch or one. The till takes it off by itself, whatever the seller
| may discount; a seller may still give more, within their own limit.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($keeper) {
        $this->soap = Product::factory()->create(['name' => 'Soap', 'category' => 'Household', 'sell_price_minor' => 1000]);
        $this->bleach = Product::factory()->create(['name' => 'Bleach', 'category' => 'Household', 'sell_price_minor' => 2000]);
        $this->rice = Product::factory()->create(['name' => 'Rice', 'category' => 'Food', 'sell_price_minor' => 5000]);
        foreach ([$this->juba, $this->wau] as $branch) {
            foreach ([$this->soap, $this->bleach, $this->rice] as $product) {
                app(Stock::class)->add($product, $branch, 'LOT-'.$product->name, null, 50, 100, $keeper);
            }
        }
    });
});

function promoUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path, 'retail');
}

function promote(array $data): void
{
    test()->actingAsMember('acme', ['retail.prices.manage'], application: 'retail');
    test()->post(promoUrl('promotions'), $data + ['name' => 'Offer', 'starts_on' => '2026-10-24'])->assertSessionHasNoErrors();
}

function promoSale(array $items, array $permissions = ['retail.sales.create', 'retail.sales.view'], ?string $branch = null)
{
    $user = test()->actingAsMember('acme', $permissions, branch: $branch ?? test()->juba, application: 'retail');
    test()->openTill($user, $branch ?? test()->juba);

    return test()->post(promoUrl('sales'), [
        'submission_id' => (string) Str::uuid(),
        'items' => $items,
        'payment_method' => 'cash',
        'amount_tendered' => '500.00',
    ]);
}

it('takes a running promotion off by itself, and keeps it on the sale line', function () {
    promote(['percent_off' => 20, 'item_id' => $this->soap->id, 'name' => 'Soap week']);
    $promotion = $this->asTenant('acme', fn () => Promotion::query()->sole());

    $user = $this->actingAsMember('acme', ['retail.sales.create'], branch: $this->juba, application: 'retail');
    $this->openTill($user, $this->juba);
    $this->get(promoUrl('sales/create'))->assertInertia(fn (Assert $page) => $page
        ->where('items.2.label', 'Soap')
        ->where('items.2.promotion', ['name' => 'Soap week', 'percentOff' => 20])
        ->where('items.1.promotion', null));

    // The seller may not discount, the promotion is given anyway.
    promoSale([['item_id' => $this->soap->id, 'quantity' => 2], ['item_id' => $this->rice->id, 'quantity' => 1]])->assertSessionHasNoErrors();

    $sale = $this->asTenant('acme', fn () => Sale::query()->with('lines')->sole());
    expect($sale->total_minor)->toBe(1600 + 5000)
        ->and($sale->discount_minor)->toBe(400)
        ->and($sale->lines->firstWhere('item_id', $this->soap->id)->promotion_id)->toBe($promotion->id)
        ->and($sale->lines->firstWhere('item_id', $this->rice->id)->promotion_id)->toBeNull();
});

it('promotes a whole category, the biggest promotion winning', function () {
    promote(['percent_off' => 10, 'category' => 'Household']);
    promote(['percent_off' => 25, 'item_id' => $this->bleach->id]);

    promoSale([['item_id' => $this->soap->id, 'quantity' => 1], ['item_id' => $this->bleach->id, 'quantity' => 1], ['item_id' => $this->rice->id, 'quantity' => 1]])
        ->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Sale::query()->sole()->total_minor))->toBe(900 + 1500 + 5000);
});

it('lets a seller give more than the promotion only within their limit', function () {
    promote(['percent_off' => 10, 'item_id' => $this->soap->id]);
    $discounter = ['retail.sales.create', 'retail.sales.view', 'retail.sales.discount'];

    // 5% asked, 10% given: the promotion wins, the seller needs no right for it.
    promoSale([['item_id' => $this->soap->id, 'quantity' => 1, 'discount_percent' => 5]])->assertSessionHasNoErrors();
    // More than the promotion, without the right to discount: refused.
    promoSale([['item_id' => $this->soap->id, 'quantity' => 1, 'discount_percent' => 15]])->assertSessionHasErrors('items.0.discount_percent');
    // With it: the seller's bigger discount, not the promotion's.
    promoSale([['item_id' => $this->soap->id, 'quantity' => 1, 'discount_percent' => 15]], $discounter)->assertSessionHasNoErrors();

    $lines = $this->asTenant('acme', fn () => Sale::query()->with('lines')->orderBy('number')->get()->map(fn (Sale $sale) => [$sale->total_minor, $sale->lines->sole()->promotion_id !== null])->all());
    expect($lines)->toBe([[900, true], [850, false]]);
});

it('runs only on its days, in its branch, until ended', function () {
    promote(['percent_off' => 50, 'item_id' => $this->rice->id, 'branch_id' => $this->wau, 'starts_on' => '2026-10-25', 'ends_on' => '2026-10-26']);
    $promotion = $this->asTenant('acme', fn () => Promotion::query()->sole());

    promoSale([['item_id' => $this->rice->id, 'quantity' => 1]], branch: $this->wau)->assertSessionHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00'));
    promoSale([['item_id' => $this->rice->id, 'quantity' => 1]])->assertSessionHasNoErrors();
    promoSale([['item_id' => $this->rice->id, 'quantity' => 1]], branch: $this->wau)->assertSessionHasNoErrors();

    $this->actingAsMember('acme', ['retail.prices.manage'], application: 'retail');
    $this->get(promoUrl('promotions'))->assertInertia(fn (Assert $page) => $page
        ->component('retail/promotions/index')
        ->where('promotions.0.status', 'running')
        ->where('promotions.0.branch', 'Wau'));
    $this->post(promoUrl("promotions/{$promotion->id}/end"))->assertSessionHasNoErrors();
    promoSale([['item_id' => $this->rice->id, 'quantity' => 1]], branch: $this->wau)->assertSessionHasNoErrors();

    $totals = $this->asTenant('acme', fn () => Sale::query()->orderBy('number')->pluck('total_minor')->all());
    expect($totals)->toBe([5000, 5000, 2500, 5000]);
});

it('checks what a promotion is on, and who may set one', function () {
    $this->actingAsMember('acme', ['retail.prices.manage'], application: 'retail');
    $this->post(promoUrl('promotions'), ['name' => 'X', 'percent_off' => 10, 'starts_on' => '2026-10-24'])->assertSessionHasErrors('item_id');
    $this->post(promoUrl('promotions'), ['name' => 'X', 'percent_off' => 10, 'starts_on' => '2026-10-24', 'item_id' => $this->soap->id, 'category' => 'Food'])
        ->assertSessionHasErrors('item_id');
    $this->post(promoUrl('promotions'), ['name' => 'X', 'percent_off' => 10, 'starts_on' => '2026-10-24', 'ends_on' => '2026-10-20', 'item_id' => $this->soap->id])
        ->assertSessionHasErrors('ends_on');

    $pharmacyPromotion = $this->asTenant('acme', fn () => Promotion::query()->create([
        'application' => 'pharmacy', 'name' => 'Clinic', 'percent_off' => 5, 'category' => 'Pain', 'starts_on' => '2026-10-01', 'created_by' => User::factory()->create()->id,
    ]));
    $this->post(promoUrl("promotions/{$pharmacyPromotion->id}/end"))->assertNotFound();

    $this->actingAsMember('acme', ['retail.sales.create'], branch: $this->juba, application: 'retail');
    $this->get(promoUrl('promotions'))->assertForbidden();
});
