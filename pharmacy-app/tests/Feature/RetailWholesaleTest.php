<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\ItemPacks;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Retail\Models\Product;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\DeliveryNote;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Retail R4 (docs/erp/retail-roadmap.md): wholesale. Customers buy at their
| price list on quotations; a confirmed order is delivered in parts, each
| delivery note taking the stock and invoicing exactly what went, on the
| customer's account. An order line is never delivered beyond its quantity.
*/

const WHOLESALE_ALL = [
    'retail.orders.view', 'retail.orders.manage', 'retail.orders.deliver', 'retail.prices.manage',
    'retail.sales.view', 'retail.sales.void', 'retail.customers.view', 'retail.customers.manage',
];

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($keeper) {
        $this->soap = Product::factory()->create(['name' => 'Soap', 'sell_price_minor' => 300]);
        app(ItemPacks::class)->sync($this->soap, [['name' => 'case', 'units' => 12]]);
        $this->case = $this->soap->packs()->sole();
        $this->brush = Product::factory()->create(['name' => 'Brush', 'sell_price_minor' => 333]);
        app(Stock::class)->add($this->soap, $this->juba, 'LOT-S', null, 100, 200, $keeper);
        app(Stock::class)->add($this->brush, $this->juba, 'LOT-B', null, 10, 150, $keeper);

        $this->trade = PriceList::query()->create(['application' => 'retail', 'name' => 'Trade']);
        $this->trade->prices()->create(['item_id' => $this->soap->id, 'item_pack_id' => null, 'price_minor' => 250]);
        $this->trade->prices()->create(['item_id' => $this->soap->id, 'item_pack_id' => $this->case->id, 'price_minor' => 2700]);

        $this->hotel = Customer::factory()->create([
            'number' => 'C-90002', 'name' => 'Juba Hotel', 'credit_limit_minor' => 20_000,
            'payment_terms_days' => 14, 'price_list_id' => $this->trade->id,
        ]);
    });
});

function wholesaleUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path, 'retail');
}

function wholesaler(array $permissions = WHOLESALE_ALL, ?string $branch = null): User
{
    $user = test()->actingAsMember('acme', $permissions, branch: $branch ?? test()->juba, application: 'retail');
    assert($user instanceof User);

    return $user;
}

/**
 * Quotes the hotel these lines (and confirms it unless told not to).
 *
 * @param  list<array<string, mixed>>  $lines
 */
function quoteHotel(array $lines, bool $confirm = true, array $extra = []): SalesOrder
{
    test()->post(wholesaleUrl('orders'), [
        'customer_id' => test()->hotel->id,
        'valid_until' => '2026-11-02',
        'confirm' => $confirm ? '1' : '0',
        'lines' => $lines,
        ...$extra,
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => SalesOrder::query()->latest('created_at')->latest('quote_number')->with('lines')->firstOrFail());
}

/**
 * @param  array<string, int>  $quantities  by order line id
 */
function deliverOrder(SalesOrder $order, array $quantities, ?string $submission = null)
{
    return test()->post(wholesaleUrl("orders/{$order->id}/deliveries"), [
        'lines' => $quantities,
        'received_by_name' => 'Storeman',
        'submission_id' => $submission ?? (string) Str::uuid(),
    ]);
}

function soapOnHand(): int
{
    return test()->asTenant('acme', fn () => (int) Batch::query()->where('item_id', test()->soap->id)->sum('quantity_on_hand'));
}

/**
 * @return list<array<string, mixed>>
 */
function wholesaleEvents(string $type): array
{
    return DB::table('platform_outbox')->where('event_type', $type)->orderBy('created_at')->get()
        ->map(fn ($row) => json_decode($row->payload, true))->all();
}

describe('price lists', function () {
    it('prices a quotation at the customer\'s list, falling back to the shop\'s prices', function () {
        wholesaler();

        $order = quoteHotel([
            ['item_id' => $this->soap->id, 'quantity' => 10],
            ['item_id' => $this->soap->id, 'pack_id' => $this->case->id, 'quantity' => 2],
            ['item_id' => $this->brush->id, 'quantity' => 3],
        ], confirm: false);

        expect($order->lines->map(fn ($line) => [$line->unit_price_minor, $line->line_total_minor])->all())
            ->toBe([[250, 2500], [2700, 5400], [333, 999]])
            ->and($order->total_minor)->toBe(8899)
            ->and($order->status)->toBe(SalesOrderStatus::Quote)
            ->and($order->price_list_id)->toBe($this->trade->id)
            ->and($order->order_number)->toBeNull();

        // Quoting moves nothing and tells Finance nothing.
        expect(soapOnHand())->toBe(100)->and(DB::table('platform_outbox')->count())->toBe(0);
    });

    it('prices a pack the list leaves out at its unit price, and a list switched off not at all', function () {
        wholesaler();
        $this->asTenant('acme', fn () => $this->trade->prices()->whereNotNull('item_pack_id')->delete());

        $order = quoteHotel([['item_id' => $this->soap->id, 'pack_id' => $this->case->id, 'quantity' => 1]], confirm: false);
        expect($order->lines[0]->unit_price_minor)->toBe(3000);

        $this->asTenant('acme', fn () => $this->trade->forceFill(['active' => false])->save());
        $order = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 1]], confirm: false);
        expect($order->lines[0]->unit_price_minor)->toBe(300)
            ->and($order->price_list_id)->toBeNull();
    });

    it('sets and clears a list\'s prices', function () {
        wholesaler(['retail.prices.manage']);

        $this->post(wholesaleUrl('price-lists'), ['name' => 'Wholesale'])->assertSessionHasNoErrors();
        $list = $this->asTenant('acme', fn () => PriceList::query()->where('name', 'Wholesale')->sole());

        $this->put(wholesaleUrl("price-lists/{$list->id}/prices"), ['prices' => [
            ['item_id' => $this->soap->id, 'pack_id' => '', 'price' => '2.40'],
            ['item_id' => $this->soap->id, 'pack_id' => $this->case->id, 'price' => '26.00'],
            ['item_id' => $this->brush->id, 'pack_id' => '', 'price' => ''],
        ]])->assertSessionHasNoErrors();
        $this->put(wholesaleUrl("price-lists/{$list->id}/prices"), ['prices' => [
            ['item_id' => $this->soap->id, 'pack_id' => '', 'price' => '2.50'],
            ['item_id' => $this->soap->id, 'pack_id' => $this->case->id, 'price' => ''],
        ]])->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => $list->prices()->get()->map(fn ($p) => [$p->item_pack_id, $p->price_minor])->all()))
            ->toBe([[null, 250]]);

        $this->get(wholesaleUrl("price-lists/{$list->id}"))->assertInertia(fn (Assert $page) => $page
            ->component('retail/price-lists/show')
            ->where('items.1.name', 'Soap')
            ->where('items.1.rows.0.priceMinor', 250)
            ->where('items.1.rows.1.retailMinor', 3600)
            ->where('items.1.rows.1.priceMinor', null));
    });

    it('keeps price lists to their managers and their industry', function () {
        wholesaler(['retail.orders.manage', 'retail.orders.view']);
        $this->get(wholesaleUrl('price-lists'))->assertForbidden();
        $this->put(wholesaleUrl("price-lists/{$this->trade->id}/prices"), ['prices' => [['item_id' => $this->soap->id, 'price' => '0.01']]])->assertForbidden();

        $pharmacyList = $this->asTenant('acme', fn () => PriceList::query()->create(['application' => 'pharmacy', 'name' => 'Clinics']));
        wholesaler(['retail.prices.manage', 'retail.customers.manage']);
        $this->get(wholesaleUrl("price-lists/{$pharmacyList->id}"))->assertNotFound();
        $this->put(wholesaleUrl("customers/{$this->hotel->id}"), [
            'name' => 'Juba Hotel', 'credit_limit' => '200.00', 'payment_terms_days' => 14, 'price_list_id' => $pharmacyList->id,
        ])->assertSessionHasErrors('price_list_id');
    });
});

describe('quotations and orders', function () {
    it('gives discounts only to members who may', function () {
        wholesaler(array_diff(WHOLESALE_ALL, ['retail.sales.discount']));
        $this->post(wholesaleUrl('orders'), [
            'customer_id' => $this->hotel->id, 'valid_until' => '2026-11-02',
            'lines' => [['item_id' => $this->soap->id, 'quantity' => 10, 'discount_percent' => 5]],
        ])->assertSessionHasErrors(['lines.0.discount_percent' => 'You may not give discounts.']);

        wholesaler([...WHOLESALE_ALL, 'retail.sales.discount']);
        $this->post(wholesaleUrl('orders'), [
            'customer_id' => $this->hotel->id, 'valid_until' => '2026-11-02',
            'lines' => [['item_id' => $this->soap->id, 'quantity' => 10, 'discount_percent' => 21]],
        ])->assertSessionHasErrors(['lines.0.discount_percent' => 'You may give at most 20% off.']);

        $order = quoteHotel([['item_id' => $this->brush->id, 'quantity' => 3, 'discount_percent' => 7]]);
        expect($order->total_minor)->toBe(929);
    });

    it('confirms a quotation as an order while it is valid', function () {
        wholesaler();
        $quote = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 4]], confirm: false);

        $this->post(wholesaleUrl("orders/{$quote->id}/confirm"))->assertSessionHasNoErrors();
        expect($quote->refresh()->status)->toBe(SalesOrderStatus::Confirmed)
            ->and($quote->order_number)->toStartWith('SO');

        $late = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 4]], confirm: false);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00'));
        $this->post(wholesaleUrl("orders/{$late->id}/confirm"))
            ->assertSessionHasErrors(['status' => "Quotation {$late->quote_number} expired on 2026-11-02: quote again at today's prices."]);
        expect($late->refresh()->status)->toBe(SalesOrderStatus::Quote);
    });

    it('cancels before delivery and closes short after', function () {
        wholesaler();
        $order = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 10]]);
        deliverOrder($order, [$order->lines[0]->id => 4])->assertSessionHasNoErrors();

        $this->post(wholesaleUrl("orders/{$order->id}/cancel"))->assertSessionHasErrors('status');
        $this->post(wholesaleUrl("orders/{$order->id}/close"))->assertSessionHasNoErrors();
        expect($order->refresh()->status)->toBe(SalesOrderStatus::Closed);

        deliverOrder($order, [$order->lines[0]->id => 6])->assertSessionHasErrors('lines');
        expect(soapOnHand())->toBe(96);

        $quote = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 1]], confirm: false);
        deliverOrder($quote, [$quote->lines[0]->id => 1])->assertSessionHasErrors('lines');
        $this->post(wholesaleUrl("orders/{$quote->id}/cancel"))->assertSessionHasNoErrors();
        expect($quote->refresh()->status)->toBe(SalesOrderStatus::Cancelled);
    });
});

describe('deliveries', function () {
    it('delivers in parts, each invoiced on account, adding up to the order', function () {
        wholesaler([...WHOLESALE_ALL, 'retail.sales.discount']);
        $order = quoteHotel([
            ['item_id' => $this->soap->id, 'pack_id' => $this->case->id, 'quantity' => 3],
            ['item_id' => $this->brush->id, 'quantity' => 3, 'discount_percent' => 7],
        ], extra: ['customer_reference' => 'LPO-881']);
        [$cases, $brushes] = [$order->lines[0], $order->lines[1]];

        deliverOrder($order, [$cases->id => 1, $brushes->id => 1])->assertSessionHasNoErrors();

        $first = $this->asTenant('acme', fn () => DeliveryNote::query()->with('sale.lines')->sole());
        expect($order->refresh()->status)->toBe(SalesOrderStatus::PartlyDelivered)
            ->and(soapOnHand())->toBe(88)
            ->and($first->sale->payment_method->value)->toBe('account')
            ->and($first->sale->customer_id)->toBe($this->hotel->id)
            ->and($first->sale->due_on->toDateString())->toBe('2026-11-02')
            ->and($first->sale->total_minor)->toBe(2700 + 310)
            ->and($first->sale->till_session_id)->toBeNull();

        $invoice = wholesaleEvents('finance.invoice.issued')[0];
        expect($invoice['invoice_number'])->toBe($first->sale->number)
            ->and($invoice['total_minor'])->toBe(3010)
            ->and($invoice['due_on'])->toBe('2026-11-02')
            ->and($invoice['customer_reference'])->toBe('Juba Hotel (C-90002)')
            ->and(collect($invoice['lines'])->pluck('cost_minor')->all())->toBe([12 * 200, 150])
            ->and(wholesaleEvents('finance.payment.received'))->toBe([]);

        $this->travel(1)->days();
        deliverOrder($order, [$cases->id => 2, $brushes->id => 2])->assertSessionHasNoErrors();

        $invoiced = $this->asTenant('acme', fn () => (int) Sale::query()->sum('total_minor'));
        expect($order->refresh()->status)->toBe(SalesOrderStatus::Delivered)
            ->and($invoiced)->toBe($order->total_minor)
            ->and($order->total_minor)->toBe(8100 + 929)
            ->and(collect(wholesaleEvents('finance.invoice.issued'))->sum('total_minor'))->toBe($order->total_minor);

        $this->get(wholesaleUrl("customers/{$this->hotel->id}"))->assertInertia(fn (Assert $page) => $page->where('owedMinor', 9029)->has('sales', 2));
        $this->get(wholesaleUrl("deliveries/{$first->id}"))->assertInertia(fn (Assert $page) => $page
            ->component('retail/deliveries/show')
            ->where('delivery.order.customerReference', 'LPO-881')
            ->where('delivery.lines.0.quantity', 1)
            ->where('delivery.lines.0.pack', 'case of 12'));
    });

    it('shares a discount over the deliveries so the whole line comes to its total', function () {
        wholesaler([...WHOLESALE_ALL, 'retail.sales.discount']);
        $order = quoteHotel([['item_id' => $this->brush->id, 'quantity' => 3, 'discount_percent' => 5]]);
        expect($order->total_minor)->toBe(949);

        foreach ([1, 2, 3] as $delivery) {
            deliverOrder($order, [$order->lines[0]->id => 1])->assertSessionHasNoErrors();
        }

        // One brush at a time would round to 316 each (948).
        expect($this->asTenant('acme', fn () => Sale::query()->orderBy('number')->pluck('total_minor')->all()))->toBe([316, 317, 316])
            ->and($order->refresh()->status)->toBe(SalesOrderStatus::Delivered);
    });

    it('never delivers a line beyond what is left of it', function () {
        wholesaler();
        $order = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 10]]);
        deliverOrder($order, [$order->lines[0]->id => 7])->assertSessionHasNoErrors();

        deliverOrder($order, [$order->lines[0]->id => 4])
            ->assertSessionHasErrors(["lines.{$order->lines[0]->id}" => 'Only 3 of Soap are still to deliver.']);
        deliverOrder($order, [])->assertSessionHasErrors('lines');
        deliverOrder($order, [$order->lines[0]->id => 0])->assertSessionHasErrors(['lines' => 'Enter what is being delivered.']);

        expect(soapOnHand())->toBe(93)
            ->and($order->lines[0]->refresh()->quantity_delivered)->toBe(7)
            ->and($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(1);
    });

    it('refuses a delivery over the customer\'s credit, taking nothing', function () {
        wholesaler();
        $this->asTenant('acme', fn () => $this->hotel->forceFill(['credit_limit_minor' => 5000])->save());
        $order = quoteHotel([['item_id' => $this->soap->id, 'pack_id' => $this->case->id, 'quantity' => 2]]);

        deliverOrder($order, [$order->lines[0]->id => 2])
            ->assertSessionHasErrors(['customer_id' => "Over Juba Hotel's credit limit of SSP 50.00: they owe SSP 0.00 and this sale is SSP 54.00."]);

        expect(soapOnHand())->toBe(100)
            ->and($this->asTenant('acme', fn () => DeliveryNote::query()->count()))->toBe(0)
            ->and(DB::table('platform_outbox')->count())->toBe(0);

        deliverOrder($order, [$order->lines[0]->id => 1])->assertSessionHasNoErrors();
    });

    it('refuses what is not in stock, taking nothing', function () {
        wholesaler();
        $order = quoteHotel([
            ['item_id' => $this->soap->id, 'quantity' => 5],
            ['item_id' => $this->brush->id, 'quantity' => 11],
        ]);

        deliverOrder($order, [$order->lines[0]->id => 5, $order->lines[1]->id => 11])
            ->assertSessionHasErrors(["lines.{$order->lines[1]->id}" => 'Only 10 of Brush in stock; 11 requested.']);

        expect(soapOnHand())->toBe(100)
            ->and($order->refresh()->status)->toBe(SalesOrderStatus::Confirmed)
            ->and($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
    });

    it('records a delivery once however often it is sent', function () {
        wholesaler();
        $order = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 10]]);
        $submission = (string) Str::uuid();

        deliverOrder($order, [$order->lines[0]->id => 3], $submission)->assertSessionHasNoErrors();
        deliverOrder($order, [$order->lines[0]->id => 3], $submission)->assertSessionHasNoErrors();

        expect(soapOnHand())->toBe(97)
            ->and($this->asTenant('acme', fn () => DeliveryNote::query()->count()))->toBe(1);
    });

    it('takes delivered goods back as a return, never by voiding the invoice', function () {
        wholesaler();
        $order = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 10]]);
        deliverOrder($order, [$order->lines[0]->id => 10])->assertSessionHasNoErrors();
        $sale = $this->asTenant('acme', fn () => Sale::query()->with('lines')->sole());

        $this->get(wholesaleUrl("sales/{$sale->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('canVoid', false)
            ->where('sale.delivery.number', fn ($number) => str_starts_with($number, 'DN')));
        $this->post(wholesaleUrl("sales/{$sale->id}/void"), ['reason' => 'Wrong'])->assertSessionHasErrors('reason');
        expect($sale->refresh()->status)->toBe(Sale::COMPLETED);

        $this->post(wholesaleUrl("sales/{$sale->id}/returns"), [
            'reason' => 'Damaged', 'lines' => [['sale_line_id' => $sale->lines[0]->id, 'quantity' => 2, 'restock' => true]],
        ])->assertSessionHasNoErrors();

        expect(soapOnHand())->toBe(92)
            ->and(wholesaleEvents('finance.sales_return.issued')[0]['total_minor'])->toBe(500);
        $this->get(wholesaleUrl("customers/{$this->hotel->id}"))->assertInertia(fn (Assert $page) => $page->where('owedMinor', 2000));
    });
});

describe('access', function () {
    it('lets viewers look, not quote or deliver', function () {
        wholesaler();
        $order = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 2]]);

        wholesaler(['retail.orders.view']);
        $this->get(wholesaleUrl('orders'))->assertInertia(fn (Assert $page) => $page->has('orders', 1)->where('canManage', false));
        $this->get(wholesaleUrl("orders/{$order->id}"))->assertInertia(fn (Assert $page) => $page->where('canDeliver', false));
        $this->get(wholesaleUrl('orders/create'))->assertForbidden();
        $this->post(wholesaleUrl("orders/{$order->id}/cancel"))->assertForbidden();
        deliverOrder($order, [$order->lines[0]->id => 2])->assertForbidden();

        wholesaler(['retail.orders.view', 'retail.orders.deliver']);
        deliverOrder($order, [$order->lines[0]->id => 2])->assertSessionHasNoErrors();
    });

    it('keeps an order to its branch and its industry', function () {
        wholesaler();
        $order = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 2]]);

        wholesaler(branch: $this->wau);
        $this->get(wholesaleUrl("orders/{$order->id}"))->assertNotFound();
        deliverOrder($order, [$order->lines[0]->id => 2])->assertNotFound();
        $this->get(wholesaleUrl('orders'))->assertInertia(fn (Assert $page) => $page->has('orders', 0));

        $this->actingAsMember('acme', ['pharmacy.sales.view', 'pharmacy.sales.create'], branch: $this->juba);
        $this->get(wholesaleUrl('orders'))->assertForbidden();
    });

    it('quotes only this industry\'s active customers', function () {
        wholesaler();
        $pharmacyCustomer = $this->asTenant('acme', fn () => Customer::factory()->create(['application' => 'pharmacy']));

        $this->post(wholesaleUrl('orders'), [
            'customer_id' => $pharmacyCustomer->id, 'valid_until' => '2026-11-02',
            'lines' => [['item_id' => $this->soap->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('customer_id');
        expect($this->asTenant('acme', fn () => SalesOrder::query()->count()))->toBe(0);
    });
});

describe('revising quotations', function () {
    it('opens a quotation filled in, and revises it under the same number', function () {
        wholesaler();
        $order = quoteHotel([['item_id' => $this->soap->id, 'pack_id' => $this->case->id, 'quantity' => 2]], confirm: false);

        $this->get(wholesaleUrl("orders/{$order->id}/edit"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('retail/orders/create')
            ->has('customers', 1)
            ->where('quotation.quoteNumber', $order->quote_number)
            ->where('quotation.lines.0.packId', $this->case->id)
            ->where('quotation.lines.0.quantity', '2')
            ->where('validUntil', '2026-11-02'));

        // The trade price of a case went up since.
        $this->asTenant('acme', fn () => $this->trade->prices()->where('item_pack_id', $this->case->id)->update(['price_minor' => 2900]));

        $this->put(wholesaleUrl("orders/{$order->id}"), [
            'valid_until' => '2026-11-09',
            'customer_reference' => 'PO-7',
            'lines' => [
                ['item_id' => $this->soap->id, 'pack_id' => $this->case->id, 'quantity' => 3],
                ['item_id' => $this->brush->id, 'quantity' => 1],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(wholesaleUrl("orders/{$order->id}"));

        $revised = $this->asTenant('acme', fn () => SalesOrder::query()->with('lines')->findOrFail($order->id));
        expect($revised->quote_number)->toBe($order->quote_number)
            ->and($revised->status)->toBe(SalesOrderStatus::Quote)
            ->and($revised->customer_reference)->toBe('PO-7')
            ->and($revised->valid_until->toDateString())->toBe('2026-11-09')
            ->and($revised->lines)->toHaveCount(2)
            ->and($revised->total_minor)->toBe(3 * 2900 + 333);
    });

    it('quotes an expired quotation again at today\'s prices, and can confirm it at once', function () {
        wholesaler();
        $order = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 4]], confirm: false);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));

        $this->post(wholesaleUrl("orders/{$order->id}/confirm"))->assertSessionHasErrors('status');
        $this->get(wholesaleUrl("orders/{$order->id}/edit"))->assertInertia(fn (Assert $page) => $page
            ->where('validUntil', '2026-11-19'));

        $this->put(wholesaleUrl("orders/{$order->id}"), [
            'valid_until' => '2026-11-19',
            'confirm' => '1',
            'lines' => [['item_id' => $this->soap->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $confirmed = $this->asTenant('acme', fn () => $order->fresh());
        expect($confirmed->status)->toBe(SalesOrderStatus::Confirmed)
            ->and($confirmed->order_number)->not->toBeNull()
            ->and($confirmed->total_minor)->toBe(4 * 250);
    });

    it('never revises a confirmed order, nor beyond what the member may discount', function () {
        wholesaler();
        $quote = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 1]], confirm: false);
        $order = quoteHotel([['item_id' => $this->soap->id, 'quantity' => 1]]);

        $this->get(wholesaleUrl("orders/{$order->id}/edit"))->assertNotFound();
        $this->put(wholesaleUrl("orders/{$order->id}"), ['valid_until' => '2026-11-02', 'lines' => [['item_id' => $this->soap->id, 'quantity' => 9]]])
            ->assertSessionHasErrors('status');

        // No sales.discount: no discounts on a revision either, and nothing changes.
        $this->put(wholesaleUrl("orders/{$quote->id}"), ['valid_until' => '2026-11-02', 'lines' => [['item_id' => $this->soap->id, 'quantity' => 1, 'discount_percent' => 10]]])
            ->assertSessionHasErrors('lines.0.discount_percent');
        expect($this->asTenant('acme', fn () => $quote->lines()->sole()->discount_percent))->toBe(0);

        wholesaler(['retail.orders.view']);
        $this->get(wholesaleUrl("orders/{$quote->id}/edit"))->assertForbidden();
        $this->put(wholesaleUrl("orders/{$quote->id}"), ['valid_until' => '2026-11-02', 'lines' => [['item_id' => $this->soap->id, 'quantity' => 2]]])->assertForbidden();
    });
});
