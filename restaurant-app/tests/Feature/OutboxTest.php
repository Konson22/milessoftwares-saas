<?php

use App\Actions\Stock;
use App\Models\AppSubmission;
use App\Models\Bill;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
| What the mobile app did offline, sent when it reaches the restaurant
| again (OutboxController): each submission applied once, with the ids the
| phone made, by the same rules as the web.
*/

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    config(['platform-client.mobile.client_id' => 'mobile-client']);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['display_name' => 'Acme Grill', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->beans = $this->asTenant('acme', fn () => MenuItem::factory()->create(['name' => 'Rice and beans', 'section' => 'Mains', 'price_minor' => 1500]));
    $this->soda = $this->asTenant('acme', fn () => MenuItem::factory()->drink()->create(['name' => 'Soda', 'section' => 'Drinks', 'price_minor' => 300]));
    $this->table = $this->asTenant('acme', fn () => DiningTable::query()->create(['branch_id' => $this->juba, 'name' => 'T1', 'area' => 'Inside', 'seats' => 4]));

    $keeper = User::factory()->create();
    $this->asTenant('acme', fn () => app(Stock::class)->opening($this->soda, $this->juba, 24, 150, $keeper));
});

function outbox(string $kind, array $data, ?string $id = null, ?string $branch = null)
{
    return test()->withHeader('X-Branch-Id', $branch ?? test()->juba)
        ->postJson(test()->workspaceUrl('acme', 'api/v1/outbox'), ['id' => $id ?? (string) Str::uuid(), 'kind' => $kind, 'data' => $data, 'queued_at' => now()->toIso8601String()]);
}

it('applies an order taken offline: opened, added, sent and paid, with the phone\'s ids', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take', 'restaurant.bills.take'], branch: $this->juba);
    $orderId = (string) Str::uuid();
    $lineId = (string) Str::uuid();

    outbox('order.open', ['order_id' => $orderId, 'service' => 'dine_in', 'dining_table_id' => $this->table->id, 'guests' => 2])
        ->assertOk()
        ->assertJsonPath('result.order_id', $orderId)
        ->assertJsonPath('result.number', 'ORD-000001')
        ->assertJsonPath('result.joined', false);

    outbox('order.add', ['order_id' => $orderId, 'lines' => [
        ['id' => $lineId, 'item_id' => $this->beans->id, 'quantity' => 2, 'note' => 'no chilli'],
        ['id' => (string) Str::uuid(), 'item_id' => $this->soda->id, 'quantity' => 2],
    ]])->assertOk();
    expect($this->asTenant('acme', fn () => OrderLine::query()->find($lineId)?->note))->toBe('no chilli');

    outbox('order.send', ['order_id' => $orderId])->assertOk()->assertJsonPath('result.ticket', 'KT-000001');

    $this->withHeader('X-Branch-Id', $this->juba)->json('POST', $this->workspaceUrl('acme', 'api/v1/app/tills'), ['opening_float' => '10.00'])->assertOk();
    outbox('order.pay', ['order_id' => $orderId, 'payment_method' => 'cash', 'tendered_minor' => 4000])
        ->assertOk()
        ->assertJsonPath('result.bill_number', 'B-000001')
        ->assertJsonPath('result.total_minor', 3600)
        ->assertJsonPath('result.change_minor', 400);

    expect($this->asTenant('acme', fn () => Order::query()->sole()->status))->toBe(Order::PAID)
        ->and($this->asTenant('acme', fn () => Bill::query()->sole()->total_minor))->toBe(3600);
});

it('never applies the same submission twice, and answers it again', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take'], branch: $this->juba);
    $orderId = (string) Str::uuid();
    $submission = (string) Str::uuid();
    $data = ['order_id' => $orderId, 'service' => 'takeaway', 'customer_name' => 'Deng'];

    outbox('order.open', $data, $submission)->assertOk()->assertJsonPath('replayed', false);
    outbox('order.add', ['order_id' => $orderId, 'lines' => [['id' => (string) Str::uuid(), 'item_id' => $this->beans->id, 'quantity' => 1]]], $add = (string) Str::uuid())->assertOk();

    outbox('order.open', $data, $submission)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('result.order_id', $orderId);
    outbox('order.add', ['order_id' => $orderId, 'lines' => [['id' => (string) Str::uuid(), 'item_id' => $this->beans->id, 'quantity' => 1]]], $add)->assertOk()->assertJsonPath('replayed', true);

    expect($this->asTenant('acme', fn () => Order::query()->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => OrderLine::query()->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => AppSubmission::query()->count()))->toBe(2);
});

it('joins the order already at the table, and says which', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take'], branch: $this->juba);
    $first = (string) Str::uuid();
    outbox('order.open', ['order_id' => $first, 'service' => 'dine_in', 'dining_table_id' => $this->table->id])->assertOk();

    outbox('order.open', ['order_id' => (string) Str::uuid(), 'service' => 'dine_in', 'dining_table_id' => $this->table->id])
        ->assertOk()
        ->assertJsonPath('result.order_id', $first)
        ->assertJsonPath('result.joined', true);
});

it('takes a line already gone, and nothing new to send, as done', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take'], branch: $this->juba);
    $orderId = (string) Str::uuid();
    outbox('order.open', ['order_id' => $orderId, 'service' => 'takeaway'])->assertOk();

    outbox('order.remove', ['order_id' => $orderId, 'line_id' => (string) Str::uuid()])->assertOk();
    outbox('order.send', ['order_id' => $orderId])->assertOk()->assertJsonPath('result.ticket', null);
    expect($this->asTenant('acme', fn () => KitchenTicket::query()->count()))->toBe(0);
});

it('refuses by the web\'s rules, for a person to deal with', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take', 'restaurant.bills.take'], branch: $this->juba);
    $orderId = (string) Str::uuid();
    outbox('order.open', ['order_id' => $orderId, 'service' => 'takeaway'])->assertOk();
    outbox('order.add', ['order_id' => $orderId, 'lines' => [['id' => (string) Str::uuid(), 'item_id' => $this->beans->id, 'quantity' => 1]]])->assertOk();
    outbox('order.send', ['order_id' => $orderId])->assertOk();

    // No till open.
    outbox('order.pay', ['order_id' => $orderId, 'payment_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    // An order of another branch looks unknown.
    outbox('order.send', ['order_id' => $orderId], branch: $this->wau)->assertStatus(409);
    outbox('order.send', ['order_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('order');
    expect($this->asTenant('acme', fn () => AppSubmission::query()->count()))->toBe(3);
});

it('needs the permission for what was done', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take'], branch: $this->juba);
    outbox('order.pay', ['order_id' => (string) Str::uuid(), 'payment_method' => 'cash'])->assertForbidden();
});

it('gives the phone what it needs to open an order offline', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take'], branch: $this->juba);

    $this->withHeader('X-Branch-Id', $this->juba)->getJson($this->workspaceUrl('acme', 'api/v1/outbox/kit'))
        ->assertOk()
        ->assertJsonCount(2, 'menu')
        ->assertJsonPath('menu.0.name', 'Soda')
        ->assertJsonPath('tillOpen', false)
        ->assertJsonPath('can.take', true)
        ->assertJsonPath('can.pay', false);
});
