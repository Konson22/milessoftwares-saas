<?php

use App\Actions\Stock;
use App\Models\Bill;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/*
| The API restaurant-electron-app calls: the member named by the app's
| platform access token, the branch by X-Branch-Id, and every web page again
| at api/v1/app/<web path> (App\Support\AppPages), its props as JSON and its
| actions as a message and where to go next. Same controllers and rules as
| the web.
*/

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    config(['platform-client.mobile.client_id' => 'mobile-client']);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme Grill', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->beans = $this->asTenant('acme', fn () => MenuItem::factory()->create(['name' => 'Rice and beans', 'section' => 'Mains', 'price_minor' => 1500]));
    $this->soda = $this->asTenant('acme', fn () => MenuItem::factory()->drink()->create(['name' => 'Soda', 'section' => 'Drinks', 'price_minor' => 300]));
    $this->table = $this->asTenant('acme', fn () => DiningTable::query()->create(['branch_id' => $this->juba, 'name' => 'T1', 'area' => 'Inside', 'seats' => 4]));

    $keeper = User::factory()->create();
    $this->asTenant('acme', fn () => app(Stock::class)->opening($this->soda, $this->juba, 24, 150, $keeper));
});

function desk(string $method, string $path, array $data = [], ?string $branch = null, ?string $from = null)
{
    $request = test()->withHeader('X-Branch-Id', $branch ?? test()->juba);
    if ($from !== null) {
        $request = $request->withHeader('X-App-Page', $from);
    }

    return $request->json($method, test()->workspaceUrl('acme', 'api/v1/app/'.ltrim($path, '/')), $data);
}

it('names the member, the workspace and the branches they work in', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take'], branch: $this->juba);

    $this->getJson($this->workspaceUrl('acme', 'api/v1/me'))
        ->assertOk()
        ->assertJsonPath('organization.name', 'Acme Grill')
        ->assertJsonPath('organization.currency', 'SSP')
        ->assertJsonCount(1, 'branches')
        ->assertJsonPath('branches.0.name', 'Juba')
        ->assertJsonPath('reason', null);
});

it('takes an order at the table, sends it to the kitchen and bills it', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take', 'restaurant.kitchen.view', 'restaurant.bills.take'], branch: $this->juba);

    desk('GET', 'floor')
        ->assertOk()
        ->assertJsonPath('component', 'floor')
        ->assertJsonPath('props.tables.0.name', 'T1')
        ->assertJsonPath('props.tables.0.order', null)
        ->assertJsonPath('props.branch.name', 'Juba');

    $opened = desk('POST', 'orders', ['service' => 'dine_in', 'dining_table_id' => $this->table->id, 'guests' => 2], from: 'floor')->assertOk();
    $orderId = $this->asTenant('acme', fn () => Order::query()->sole()->id);
    expect($opened->json('redirect'))->toBe("orders/{$orderId}");

    desk('POST', "orders/{$orderId}/lines", ['lines' => [
        ['item_id' => $this->beans->id, 'quantity' => 2, 'note' => 'no chilli'],
        ['item_id' => $this->soda->id, 'quantity' => 2],
    ]], from: "orders/{$orderId}")->assertOk()->assertJsonPath('redirect', null);

    desk('POST', "orders/{$orderId}/send", from: "orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('message', 'Sent to the kitchen on ticket KT-000001.');

    $ticket = $this->asTenant('acme', fn () => KitchenTicket::query()->sole());
    desk('GET', 'kitchen')->assertOk()->assertJsonPath('props.tickets.0.number', 'KT-000001')->assertJsonPath('props.tickets.0.lines.0.note', 'no chilli');
    desk('POST', "kitchen/{$ticket->id}/ready", from: 'kitchen')->assertOk()->assertJsonPath('message', 'Ticket KT-000001 is ready.');

    desk('GET', "orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('component', 'orders/show')
        ->assertJsonPath('props.bill.total_minor', 3600)
        ->assertJsonPath('props.tillOpen', false)
        ->assertJsonPath('props.can.pay', true);

    desk('POST', 'tills', ['opening_float' => '10.00'], from: "orders/{$orderId}")->assertOk()->assertJsonPath('redirect', 'tills');

    desk('POST', "orders/{$orderId}/pay", ['payment_method' => 'cash', 'amount_tendered' => '40.00'], from: "orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('message', 'Bill B-000001 paid, change SSP 4.00.');

    expect($this->asTenant('acme', fn () => Bill::query()->sole()->total_minor))->toBe(3600);
});

it('answers validation as the web does', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take'], branch: $this->juba);

    desk('POST', 'orders', ['service' => 'dine_in'])->assertUnprocessable()->assertJsonValidationErrors('dining_table_id');
});

it('works only in a branch open to the member, with its permissions there', function () {
    $this->actingAsAppMember('acme', ['restaurant.orders.take'], branch: $this->juba);

    $this->getJson($this->workspaceUrl('acme', 'api/v1/app/floor'))->assertStatus(409)->assertJsonPath('code', 'branch_required');
    desk('GET', 'floor', branch: $this->wau)->assertStatus(409);
    desk('GET', 'kitchen')->assertForbidden();
});

it('refuses calls without the app\'s token', function () {
    $this->withHeader('X-Branch-Id', $this->juba)->getJson($this->workspaceUrl('acme', 'api/v1/app/floor'))->assertUnauthorized();
    $this->getJson($this->workspaceUrl('acme', 'api/v1/me'))->assertUnauthorized();
});
