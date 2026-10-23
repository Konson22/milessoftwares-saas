<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Retail\Models\Product;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ItemSerial;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Serial numbers: a product that tracks them is sold one recorded serial per
| unit; the serial keeps the sale it left in. A void puts them back in
| stock, a return names those that came back.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($keeper) {
        $this->phone = Product::factory()->create(['name' => 'Phone', 'sell_price_minor' => 100_000, 'tracks_serials' => true]);
        $this->soap = Product::factory()->create(['name' => 'Soap', 'sell_price_minor' => 1000]);
        app(Stock::class)->add($this->phone, $this->juba, 'LOT-P', null, 5, 60_000, $keeper);
        app(Stock::class)->add($this->soap, $this->juba, 'LOT-S', null, 5, 500, $keeper);
    });

    $this->actingAsMember('acme', ['retail.stock.view', 'retail.stock.adjust'], branch: $this->juba, application: 'retail');
    $this->post(serialUrl('serials'), ['item_id' => $this->phone->id, 'serials' => "IMEI-1\nIMEI-2\r\nIMEI-3, IMEI-3\n\n"])->assertSessionHasNoErrors();
});

function serialUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path, 'retail');
}

function serialSale(array $items, array $extra = [])
{
    $user = test()->actingAsMember('acme', ['retail.sales.create', 'retail.sales.view', 'retail.sales.void'], branch: test()->juba, application: 'retail');
    test()->openTill($user, test()->juba);

    return test()->post(serialUrl('sales'), $extra + [
        'submission_id' => (string) Str::uuid(),
        'items' => $items,
        'payment_method' => 'cash',
    ]);
}

function serialStatus(string $serial): string
{
    return test()->asTenant('acme', fn () => ItemSerial::query()->where('serial', $serial)->value('status'));
}

it('records serials once, and shows what is in stock', function () {
    expect($this->asTenant('acme', fn () => ItemSerial::query()->count()))->toBe(3);

    $this->post(serialUrl('serials'), ['item_id' => $this->phone->id, 'serials' => "IMEI-3\nIMEI-4"])->assertSessionHasErrors('serials');
    $this->post(serialUrl('serials'), ['item_id' => $this->soap->id, 'serials' => 'X-1'])->assertSessionHasErrors('item_id');

    $this->get(serialUrl('serials'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('retail/serials/index')
        ->where('items', [['id' => $this->phone->id, 'label' => 'Phone', 'inStock' => 3]])
        ->has('serials', 3)
        ->where('canRecord', true));

    $this->actingAsMember('acme', ['retail.stock.view'], branch: $this->juba, application: 'retail');
    $this->post(serialUrl('serials'), ['item_id' => $this->phone->id, 'serials' => 'IMEI-9'])->assertForbidden();
});

it('sells a tracked product only with the serial of each unit', function () {
    serialSale([['item_id' => $this->phone->id, 'quantity' => 2, 'serials' => ['IMEI-1']]])->assertSessionHasErrors('items.0.serials');
    serialSale([['item_id' => $this->phone->id, 'quantity' => 1, 'serials' => ['NOPE']]])->assertSessionHasErrors('items.0.serials');
    expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);

    serialSale([
        ['item_id' => $this->phone->id, 'quantity' => 2, 'serials' => ['IMEI-1', 'IMEI-2']],
        ['item_id' => $this->soap->id, 'quantity' => 1],
    ], ['customer_name' => 'Deng'])->assertSessionHasNoErrors();
    $sale = $this->asTenant('acme', fn () => Sale::query()->sole());

    expect(serialStatus('IMEI-1'))->toBe('sold')
        ->and(serialStatus('IMEI-3'))->toBe('in_stock')
        ->and($this->asTenant('acme', fn () => ItemSerial::query()->where('serial', 'IMEI-2')->value('sale_id')))->toBe($sale->id);

    // Sold once only.
    serialSale([['item_id' => $this->phone->id, 'quantity' => 1, 'serials' => ['IMEI-2']]])->assertSessionHasErrors('items.0.serials');

    $this->get(serialUrl("sales/{$sale->id}"))->assertInertia(fn (Assert $page) => $page->where('sale.serials', ['IMEI-1', 'IMEI-2']));
    $this->actingAsMember('acme', ['retail.stock.view'], branch: $this->juba, application: 'retail');
    $this->get(serialUrl('serials?serial=IMEI-2'))->assertInertia(fn (Assert $page) => $page
        ->has('serials', 1)
        ->where('serials.0.sale.number', $sale->number)
        ->where('serials.0.sale.customer', 'Deng'));
});

it('puts serials back in stock on a void, and on a return naming them', function () {
    serialSale([['item_id' => $this->phone->id, 'quantity' => 1, 'serials' => ['IMEI-1']]])->assertSessionHasNoErrors();
    $first = $this->asTenant('acme', fn () => Sale::query()->sole());
    $this->post(serialUrl("sales/{$first->id}/void"), ['reason' => 'Wrong phone'])->assertSessionHasNoErrors();
    expect(serialStatus('IMEI-1'))->toBe('in_stock');

    serialSale([['item_id' => $this->phone->id, 'quantity' => 2, 'serials' => ['IMEI-1', 'IMEI-3']]])->assertSessionHasNoErrors();
    $second = $this->asTenant('acme', fn () => Sale::query()->where('status', Sale::COMPLETED)->with('lines')->sole());
    $line = $second->lines->sole();

    // The serial must be one this sale sold, one per unit coming back.
    $this->post(serialUrl("sales/{$second->id}/returns"), ['reason' => 'Faulty', 'lines' => [['sale_line_id' => $line->id, 'quantity' => 1, 'serials_text' => 'IMEI-2']]])
        ->assertSessionHasErrors('lines.0.serials');
    $this->post(serialUrl("sales/{$second->id}/returns"), ['reason' => 'Faulty', 'lines' => [['sale_line_id' => $line->id, 'quantity' => 1]]])
        ->assertSessionHasErrors('lines.0.serials');
    $this->post(serialUrl("sales/{$second->id}/returns"), ['reason' => 'Faulty', 'lines' => [['sale_line_id' => $line->id, 'quantity' => 1, 'serials_text' => 'IMEI-3']]])
        ->assertSessionHasNoErrors();

    expect(serialStatus('IMEI-3'))->toBe('in_stock')
        ->and(serialStatus('IMEI-1'))->toBe('sold');
});

it('keeps tracked products off quotations', function () {
    $hotel = $this->asTenant('acme', fn () => Customer::factory()->create(['credit_limit_minor' => 1_000_000]));
    $this->actingAsMember('acme', ['retail.orders.view', 'retail.orders.manage'], branch: $this->juba, application: 'retail');

    $this->post(serialUrl('orders'), [
        'customer_id' => $hotel->id,
        'valid_until' => '2026-11-07',
        'lines' => [['item_id' => $this->phone->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('lines.0.item_id');
});

it('turns serial tracking on and off from the product form', function () {
    $this->actingAsMember('acme', ['retail.products.view', 'retail.products.manage'], application: 'retail');

    $this->put(serialUrl("products/{$this->soap->id}"), [
        'name' => 'Soap', 'unit' => 'bar', 'sell_price' => '10.00', 'reorder_level' => 0, 'tracks_serials' => '1',
    ])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $this->soap->fresh()->tracks_serials))->toBeTrue();

    $this->get(serialUrl("products/{$this->soap->id}/edit"))->assertInertia(fn (Assert $page) => $page->where('product.tracksSerials', true));
});
