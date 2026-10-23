<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\ItemPacks;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Retail\Models\Product;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Price levels per branch: a branch's counter may sell at a price list of
| its own (ShelfPrices); what the list does not price, and every other
| branch, sells at the shop's own price. Quotations fall back to the
| branch's shelf price where the customer's own list is silent.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($keeper) {
        $this->soap = Product::factory()->create(['name' => 'Soap', 'sell_price_minor' => 300]);
        app(ItemPacks::class)->sync($this->soap, [['name' => 'case', 'units' => 12]]);
        $this->case = $this->soap->packs()->sole();
        $this->brush = Product::factory()->create(['name' => 'Brush', 'sell_price_minor' => 500]);
        foreach ([$this->juba, $this->wau] as $branch) {
            app(Stock::class)->add($this->soap, $branch, 'LOT-S', null, 100, 200, $keeper);
            app(Stock::class)->add($this->brush, $branch, 'LOT-B', null, 10, 150, $keeper);
        }

        // Juba is in the city centre: soap costs more there, by the unit.
        $this->city = PriceList::query()->create(['application' => 'retail', 'name' => 'City centre']);
        $this->city->prices()->create(['item_id' => $this->soap->id, 'item_pack_id' => null, 'price_minor' => 350]);
    });
});

function levelUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path, 'retail');
}

function setLevel(?string $listId): void
{
    test()->actingAsMember('acme', ['retail.sales.settings'], branch: test()->juba, application: 'retail');
    test()->post(levelUrl('counter-settings'), [
        'workflow' => 'direct',
        'paper_size' => '80mm',
        'price_list_id' => $listId,
    ])->assertSessionHasNoErrors();
}

function levelSeller(?string $branch = null): User
{
    $user = test()->actingAsMember('acme', ['retail.sales.create', 'retail.sales.view'], branch: $branch ?? test()->juba, application: 'retail');
    test()->openTill($user, $branch ?? test()->juba);
    assert($user instanceof User);

    return $user;
}

it('sets a branch price level in its counter settings', function () {
    setLevel($this->city->id);

    $this->get(levelUrl('counter-settings'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('retail/counter/settings')
        ->where('settings.priceListId', $this->city->id)
        ->where('priceLists', [['id' => $this->city->id, 'name' => 'City centre']]));

    // Saving the other settings leaves the level alone; an empty choice clears it.
    $this->post(levelUrl('counter-settings'), ['workflow' => 'direct', 'paper_size' => '58mm'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => BranchCounterSettings::forBranch($this->juba, 'retail')->price_list_id))->toBe($this->city->id);

    setLevel(null);
    expect($this->asTenant('acme', fn () => BranchCounterSettings::forBranch($this->juba, 'retail')->price_list_id))->toBeNull();
});

it('sells at the branch level, and at the shop prices elsewhere', function () {
    setLevel($this->city->id);
    levelSeller();

    $this->get(levelUrl('sales/create'))->assertInertia(fn (Assert $page) => $page
        ->where('items.0.label', 'Brush')
        ->where('items.0.priceMinor', 500)
        ->where('items.1.label', 'Soap')
        ->where('items.1.priceMinor', 350)
        ->where('items.1.packs.0.priceMinor', 12 * 350));

    foreach ([
        [['item_id' => $this->soap->id, 'quantity' => 2], ['item_id' => $this->brush->id, 'quantity' => 1]],
        [['item_id' => $this->soap->id, 'pack_id' => $this->case->id, 'quantity' => 1]],
    ] as $items) {
        $this->post(levelUrl('sales'), [
            'submission_id' => (string) Str::uuid(),
            'items' => $items,
            'payment_method' => 'cash',
            'amount_tendered' => '100.00',
        ])->assertSessionHasNoErrors();
    }

    [$loose, $case] = $this->asTenant('acme', fn () => Sale::query()->with('lines')->orderBy('number')->get()->all());
    expect($loose->total_minor)->toBe(2 * 350 + 500)
        ->and($loose->lines->firstWhere('item_id', $this->soap->id)->unit_price_minor)->toBe(350)
        ->and($case->total_minor)->toBe(12 * 350)
        ->and($case->lines->sole()->pack_price_minor)->toBe(12 * 350);

    // Wau has no level of its own.
    levelSeller($this->wau);
    $this->get(levelUrl('sales/create'))->assertInertia(fn (Assert $page) => $page->where('items.1.priceMinor', 300));
});

it('sells at the shop prices again when the list is switched off', function () {
    setLevel($this->city->id);
    $this->asTenant('acme', fn () => $this->city->forceFill(['active' => false])->save());
    levelSeller();

    $this->get(levelUrl('sales/create'))->assertInertia(fn (Assert $page) => $page->where('items.1.priceMinor', 300));
});

it('quotes at the customer list first, then the branch level', function () {
    setLevel($this->city->id);
    [$walkIn, $hotel] = $this->asTenant('acme', function () {
        $trade = PriceList::query()->create(['application' => 'retail', 'name' => 'Trade']);
        $trade->prices()->create(['item_id' => $this->brush->id, 'item_pack_id' => null, 'price_minor' => 400]);

        return [
            Customer::factory()->create(['name' => 'Corner shop', 'credit_limit_minor' => 50_000]),
            Customer::factory()->create(['name' => 'Juba Hotel', 'credit_limit_minor' => 50_000, 'price_list_id' => $trade->id]),
        ];
    });
    $this->actingAsMember('acme', ['retail.orders.view', 'retail.orders.manage'], branch: $this->juba, application: 'retail');

    foreach ([$walkIn, $hotel] as $customer) {
        $this->post(levelUrl('orders'), [
            'customer_id' => $customer->id,
            'valid_until' => '2026-11-07',
            'lines' => [['item_id' => $this->soap->id, 'quantity' => 1], ['item_id' => $this->brush->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();
    }

    $totals = $this->asTenant('acme', fn () => SalesOrder::query()->pluck('total_minor', 'customer_id'));
    expect($totals[$walkIn->id])->toBe(350 + 500)
        ->and($totals[$hotel->id])->toBe(350 + 400);
});

it('takes only a price list of this industry, from someone who sets the counter', function () {
    $pharmacyList = $this->asTenant('acme', fn () => PriceList::query()->create(['application' => 'pharmacy', 'name' => 'Clinic']));

    $this->actingAsMember('acme', ['retail.sales.settings'], branch: $this->juba, application: 'retail');
    $this->post(levelUrl('counter-settings'), ['workflow' => 'direct', 'paper_size' => '80mm', 'price_list_id' => $pharmacyList->id])
        ->assertSessionHasErrors('price_list_id');

    $this->actingAsMember('acme', ['retail.sales.create'], branch: $this->juba, application: 'retail');
    $this->get(levelUrl('counter-settings'))->assertForbidden();
});
