<?php

use App\Actions\Stock;
use App\Actions\Tills;
use App\Models\Bill;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockDelivery;
use App\Models\StockLevel;
use App\Models\TillShift;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\SharedModuleKit\Envelope;

/*
| The restaurant (docs/erp/restaurant-roadmap.md): its menu, tables, orders
| sent to the kitchen, bills paid into the cashier's till and the drinks
| they take from stock. Dishes keep no stock; drinks do.
*/

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->beans = $this->asTenant('acme', fn () => MenuItem::factory()->create(['name' => 'Rice and beans', 'price_minor' => 1500]));
    $this->soda = $this->asTenant('acme', fn () => MenuItem::factory()->drink()->create(['name' => 'Soda', 'price_minor' => 300]));
    $this->table = $this->asTenant('acme', fn () => DiningTable::query()->create(['branch_id' => $this->juba, 'name' => 'T1', 'area' => 'Inside', 'seats' => 4]));
    $this->terrace = $this->asTenant('acme', fn () => DiningTable::query()->create(['branch_id' => $this->juba, 'name' => 'T2', 'area' => 'Terrace', 'seats' => 2]));

    // A crate of 24 sodas at 1.50 each in Juba.
    $keeper = User::factory()->create();
    $this->asTenant('acme', fn () => app(Stock::class)->opening($this->soda, $this->juba, 24, 150, $keeper));
    $this->platform()->moduleEvents = [];
});

/**
 * Signs in someone working at the Juba branch; with their till open when
 * they take payment. Access is remembered per user within a test, so each
 * role signs in as someone new.
 *
 * @param  list<string>  $permissions
 */
function member(array $permissions = ['restaurant.orders.take'], ?User $user = null): User
{
    $member = test()->actingAsMember('acme', $permissions, branch: test()->juba, user: $user);
    assert($member instanceof User);
    if (in_array('restaurant.bills.take', $permissions, true) && $user === null) {
        test()->post(at('tills'), ['opening_float' => '10.00'])->assertSessionHasNoErrors();
    }

    return $member;
}

/**
 * An order at table T1 with lines, sent to the kitchen unless asked not to.
 *
 * @param  list<array{0: MenuItem, 1: int, 2?: string}>  $lines
 */
function tableOrder(array $lines, bool $send = true): Order
{
    test()->post(at('orders'), ['service' => 'dine_in', 'dining_table_id' => test()->table->id, 'guests' => 2])->assertSessionHasNoErrors();
    $order = test()->asTenant('acme', fn () => Order::query()->where('dining_table_id', test()->table->id)->where('status', 'open')->sole());

    test()->post(at("orders/{$order->id}/lines"), [
        'lines' => array_map(fn (array $line) => ['item_id' => $line[0]->id, 'quantity' => $line[1], 'note' => $line[2] ?? null], $lines),
    ])->assertSessionHasNoErrors();
    if ($send) {
        test()->post(at("orders/{$order->id}/send"))->assertSessionHasNoErrors();
    }

    return $order;
}

/**
 * A page's address. Branch pages (the floor, orders, bills...) live under
 * branches/{branch}: in Juba unless another branch is named.
 */
function at(string $path = '', ?string $branch = null): string
{
    $section = explode('/', strtok($path, '?') ?: '')[0];
    $inBranch = $branch !== null || in_array($section, ['floor', 'orders', 'kitchen', 'bills', 'reports', 'tills', 'stock', 'tables'], true);

    return test()->workspaceUrl('acme', $inBranch ? rtrim('branches/'.($branch ?? test()->juba).'/'.$path, '/') : $path);
}

function sodasInJuba(): int
{
    return test()->asTenant('acme', fn () => StockLevel::query()->where('branch_id', test()->juba)->where('menu_item_id', test()->soda->id)->value('quantity'));
}

/**
 * @return list<string>
 */
function financeEvents(): array
{
    return array_map(fn (array $event) => Envelope::fromArray($event)->eventType, test()->platform()->moduleEvents['finance'] ?? []);
}

describe('branches', function () {
    it('lists the branches when there are several, each opening its own workspace', function () {
        $this->actingAsMember('acme', ['restaurant.orders.take']);

        $this->get(at())->assertRedirect(at('branch'));
        $this->get(at('branch'))->assertInertia(fn (Assert $page) => $page
            ->component('branch')
            ->has('branches', 2)
            ->where('branches.1.url', at('', $this->wau)));

        $this->get(at('floor', $this->wau))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('area', 'branch')
            ->where('branch.name', 'Wau')
            ->has('tables', 0));
    });

    it('keeps each tab in the branch its address names', function () {
        $this->actingAsMember('acme', ['restaurant.orders.take', 'restaurant.tables.manage']);

        // Two tabs, one session: neither moves the other.
        $this->get(at('floor', $this->wau))->assertInertia(fn (Assert $page) => $page->where('branch.name', 'Wau'));
        $this->get(at('floor'))->assertInertia(fn (Assert $page) => $page->where('branch.name', 'Juba')->has('tables', 2));
        $this->post(at('orders'), ['service' => 'takeaway'])->assertSessionHasNoErrors();
        $this->get(at('floor', $this->wau))->assertInertia(fn (Assert $page) => $page->where('branch.name', 'Wau')->has('takeaways', 0));
        $this->get(at('floor'))->assertInertia(fn (Assert $page) => $page->has('takeaways', 1));

        // What a page does stays in its branch: Juba's order is not Wau's.
        $order = $this->asTenant('acme', fn () => Order::query()->sole());
        $this->get(at("orders/{$order->id}", $this->wau))->assertNotFound();
        $this->get(at("orders/{$order->id}"))->assertOk();
    });

    it('opens old addresses in the branch last worked in, or asks which', function () {
        $this->actingAsMember('acme', ['restaurant.orders.take']);

        $this->get($this->workspaceUrl('acme', 'floor'))->assertRedirect(at('branch').'?page=floor');
        $this->get(at('branch').'?page=floor')->assertInertia(fn (Assert $page) => $page->where('branches.1.url', at('floor', $this->wau)));

        $this->get(at('orders', $this->wau));
        $this->get($this->workspaceUrl('acme', 'orders?day=2026-10-25'))->assertRedirect(at('orders?day=2026-10-25', $this->wau));
        $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect(at('', $this->juba));
    });

    it('opens only the branches the member has a role in', function () {
        member();

        $this->get(at('floor'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('branch.name', 'Juba'));
        $this->get(at())->assertRedirect(at('', $this->juba));
        $this->get(at('floor', $this->wau))->assertNotFound();
        $this->post(at('orders', $this->wau), ['service' => 'takeaway'])->assertNotFound();
        $this->get(at('floor', (string) Str::uuid()))->assertNotFound();
        $this->put(at('branch'), ['branch_id' => $this->wau])->assertNotFound();
    });

    it('counts a role only in its own branch', function () {
        $user = $this->actingAsMember('acme', ['restaurant.tables.manage'], branch: $this->wau);
        // Takes orders in Juba, sets up tables only in Wau.
        $this->platform()->grant('acme', (string) $user->getAuthIdentifier(), [
            ['permissions' => ['restaurant.orders.take'], 'scope' => ['scope_type' => 'branch', 'branch_id' => $this->juba]],
            ['permissions' => ['restaurant.tables.manage'], 'scope' => ['scope_type' => 'branch', 'branch_id' => $this->wau]],
        ]);

        $this->get(at('floor'))->assertOk();
        $this->get(at('tables'))->assertForbidden();
    });
});

describe('access', function () {
    it('lets only those who take payment pay a bill', function () {
        member();
        $order = tableOrder([[$this->beans, 1]]);

        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash'])->assertForbidden();
    });

    it('keeps the kitchen screen to the kitchen', function () {
        member();

        $this->get(at('kitchen'))->assertForbidden();
    });
});

describe('the menu', function () {
    it('lists dishes and drinks', function () {
        $this->actingAsMember('acme', ['restaurant.menu.view']);

        $this->get(at('menu'))->assertInertia(fn (Assert $page) => $page
            ->component('menu/index')
            ->has('items', 2)
            ->where('items.0.name', 'Soda')
            ->where('items.0.kind', 'drink')
            ->where('items.1.name', 'Rice and beans')
            ->where('canManage', false));
    });

    it('adds a dish that keeps no stock and a drink that does', function () {
        $this->actingAsMember('acme', ['restaurant.menu.view', 'restaurant.menu.manage']);

        $this->post(at('menu'), ['kind' => 'dish', 'name' => ' Goat stew ', 'section' => 'mains', 'price' => '25.00', 'barcode' => '123'])
            ->assertRedirect(at('menu'))->assertSessionHasNoErrors();
        $this->post(at('menu'), ['kind' => 'drink', 'name' => 'Water 500ml', 'section' => 'Drinks', 'price' => '1.00', 'reorder_level' => 12])
            ->assertSessionHasNoErrors();

        [$stew, $water] = $this->asTenant('acme', fn () => [MenuItem::query()->where('name', 'Goat stew')->sole(), MenuItem::query()->where('name', 'Water 500ml')->sole()]);
        expect($stew->tracksStock())->toBeFalse()
            ->and($stew->unit)->toBe('plate')
            ->and($stew->barcode)->toBeNull()
            // One spelling per section, whoever typed it first.
            ->and($stew->section)->toBe('Mains')
            ->and($stew->price_minor)->toBe(2500)
            ->and($water->tracksStock())->toBeTrue()
            ->and($water->unit)->toBe('bottle')
            ->and($water->reorder_level)->toBe(12);
    });

    it('keeps a stocked drink a drink, and another workspace\'s items out of reach', function () {
        $this->actingAsMember('acme', ['restaurant.menu.manage']);
        $theirs = $this->asTenant('globex', fn () => MenuItem::factory()->create());

        $this->put(at("menu/{$this->soda->id}"), ['kind' => 'dish', 'name' => 'Soda', 'price' => '3.00'])->assertSessionHasErrors('kind');
        $this->get(at("menu/{$theirs->id}/edit"))->assertNotFound();
    });
});

describe('tables', function () {
    it('adds tables to the branch, one name each', function () {
        member(['restaurant.tables.manage']);

        $this->post(at('tables'), ['name' => 'T3', 'area' => 'Terrace', 'seats' => 6])->assertSessionHasNoErrors();
        $this->post(at('tables'), ['name' => 'T1', 'seats' => 2])->assertSessionHasErrors('name');

        $this->get(at('tables'))->assertInertia(fn (Assert $page) => $page
            ->component('tables/index')
            ->has('tables', 3)
            ->where('areas', ['Inside', 'Terrace']));
    });

    it('keeps a table in use while it is being served', function () {
        member(['restaurant.orders.take', 'restaurant.tables.manage']);
        tableOrder([[$this->beans, 1]], send: false);

        $this->put(at("tables/{$this->table->id}"), ['name' => 'T1', 'seats' => 4, 'active' => false])->assertSessionHasErrors('active');
    });
});

describe('orders', function () {
    it('gives the open order of a table to whoever opens it again', function () {
        member();
        $first = tableOrder([[$this->beans, 1]], send: false);

        $this->post(at('orders'), ['service' => 'dine_in', 'dining_table_id' => $this->table->id])
            ->assertRedirect(at("orders/{$first->id}"));
        expect($this->asTenant('acme', fn () => Order::query()->count()))->toBe(1);
    });

    it('opens a takeaway without a table', function () {
        member();

        $this->post(at('orders'), ['service' => 'takeaway', 'customer_name' => 'Deng'])->assertSessionHasNoErrors();
        $order = $this->asTenant('acme', fn () => Order::query()->sole());
        expect($order->service)->toBe('takeaway')->and($order->dining_table_id)->toBeNull()->and($order->number)->toBe('ORD-000001');

        $this->get(at('floor'))->assertInertia(fn (Assert $page) => $page
            ->component('floor')
            ->has('tables', 2)
            ->has('takeaways', 1)
            ->where('takeaways.0.customerName', 'Deng'));
    });

    it('refuses to open at a table of another branch, and hides that branch\'s orders', function () {
        member();
        $elsewhere = $this->asTenant('acme', fn () => DiningTable::query()->create(['branch_id' => $this->wau, 'name' => 'W1', 'seats' => 4]));
        $theirs = $this->asTenant('acme', fn () => Order::query()->create([
            'branch_id' => $this->wau, 'number' => 'ORD-999999', 'service' => 'takeaway', 'status' => 'open',
            'opened_by' => User::factory()->create()->id, 'opened_at' => now(),
        ]));

        $this->post(at('orders'), ['service' => 'dine_in', 'dining_table_id' => $elsewhere->id])->assertSessionHasErrors('dining_table_id');
        $this->get(at("orders/{$theirs->id}"))->assertNotFound();
    });

    it('takes only what is on the menu', function () {
        member();
        $order = tableOrder([[$this->beans, 1]], send: false);
        $off = $this->asTenant('acme', fn () => MenuItem::factory()->create(['active' => false]));

        $this->post(at("orders/{$order->id}/lines"), ['lines' => [['item_id' => $off->id, 'quantity' => 1]]])->assertSessionHasErrors('lines');
        expect($this->asTenant('acme', fn () => OrderLine::query()->count()))->toBe(1);
    });

    it('sends only the new lines to the kitchen, once', function () {
        member();
        $order = tableOrder([[$this->beans, 2, 'no onions']]);
        $this->post(at("orders/{$order->id}/send"))->assertSessionHasErrors('lines');

        $this->post(at("orders/{$order->id}/lines"), ['lines' => [['item_id' => $this->soda->id, 'quantity' => 1]]]);
        $this->post(at("orders/{$order->id}/send"))->assertSessionHasNoErrors();

        $tickets = $this->asTenant('acme', fn () => KitchenTicket::query()->withCount('lines')->orderBy('number')->get());
        expect($tickets->pluck('number')->all())->toBe(['KT-000001', 'KT-000002'])
            ->and($tickets->pluck('lines_count')->all())->toBe([1, 1]);

        $this->get(at("orders/{$order->id}"))->assertInertia(fn (Assert $page) => $page
            ->component('orders/show')
            ->where('order.lines.0.note', 'no onions')
            ->where('order.lines.0.status', 'preparing')
            ->where('bill.total_minor', 3300));
    });

    it('removes a line before the kitchen has it, and voids it after', function () {
        member();
        $order = tableOrder([[$this->beans, 1], [$this->soda, 2]]);
        [$beans] = $this->asTenant('acme', fn () => OrderLine::query()->orderBy('quantity')->get()->all());

        $this->delete(at("orders/{$order->id}/lines/{$beans->id}"))->assertSessionHasErrors('line');
        $this->post(at("orders/{$order->id}/lines/{$beans->id}/void"), ['reason' => 'Changed mind'])->assertForbidden();

        // The manager voids it.
        member(['restaurant.orders.take', 'restaurant.orders.void']);
        $this->post(at("orders/{$order->id}/lines/{$beans->id}/void"), ['reason' => 'Changed mind'])->assertSessionHasNoErrors();
        $this->get(at("orders/{$order->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('order.lines.0.status', 'voided')
            ->where('bill.total_minor', 600));

        $this->post(at("orders/{$order->id}/lines"), ['lines' => [['item_id' => $this->beans->id, 'quantity' => 1]]]);
        $new = $this->asTenant('acme', fn () => OrderLine::query()->whereNull('kitchen_ticket_id')->sole());
        $this->delete(at("orders/{$order->id}/lines/{$new->id}"))->assertSessionHasNoErrors();
        expect($this->asTenant('acme', fn () => OrderLine::query()->count()))->toBe(2);
    });

    it('moves an order to a free table only', function () {
        member();
        $order = tableOrder([[$this->beans, 1]]);

        $this->post(at("orders/{$order->id}/move"), ['dining_table_id' => $this->terrace->id])->assertSessionHasNoErrors();
        expect($this->asTenant('acme', fn () => $order->fresh()->dining_table_id))->toBe($this->terrace->id);

        // T1 is free again; seat someone there and try to move onto them.
        tableOrder([[$this->soda, 1]]);
        $this->post(at("orders/{$order->id}/move"), ['dining_table_id' => $this->table->id])->assertSessionHasErrors('dining_table_id');
    });

    it('cancels an order, voiding what the kitchen has only with the right to void', function () {
        member();
        $order = tableOrder([[$this->beans, 1]]);

        $this->post(at("orders/{$order->id}/cancel"), ['reason' => 'Guests left'])->assertForbidden();

        member(['restaurant.orders.take', 'restaurant.orders.void']);
        $this->post(at("orders/{$order->id}/cancel"), ['reason' => 'Guests left'])->assertRedirect(at('floor'));

        expect($this->asTenant('acme', fn () => $order->fresh()->status))->toBe('cancelled')
            ->and($this->asTenant('acme', fn () => OrderLine::query()->whereNull('voided_at')->count()))->toBe(0);
        $this->post(at("orders/{$order->id}/lines"), ['lines' => [['item_id' => $this->soda->id, 'quantity' => 1]]])->assertSessionHasErrors('lines');
    });
});

describe('the kitchen', function () {
    it('shows tickets with their notes, ready then served', function () {
        $waiter = member();
        tableOrder([[$this->beans, 2, 'well done']]);
        $ticket = $this->asTenant('acme', fn () => KitchenTicket::query()->sole());

        $cook = member(['restaurant.kitchen.view']);
        $this->get(at('kitchen'))->assertInertia(fn (Assert $page) => $page
            ->component('kitchen')
            ->has('tickets', 1)
            ->where('tickets.0.order.table', 'T1')
            ->where('tickets.0.lines.0.note', 'well done'));

        $this->post(at("kitchen/{$ticket->id}/served"))->assertSessionHasErrors('ticket');
        $this->post(at("kitchen/{$ticket->id}/ready"))->assertSessionHasNoErrors();
        $this->post(at("kitchen/{$ticket->id}/ready"))->assertSessionHasErrors('ticket');

        $this->actingAs($waiter);
        $this->get(at('floor'))->assertInertia(fn (Assert $page) => $page->where('tables.0.order.hasReady', true));
        $this->post(at("kitchen/{$ticket->id}/ready"))->assertForbidden();
        $this->post(at("kitchen/{$ticket->id}/served"))->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => $ticket->fresh()->status))->toBe('served');
        $this->actingAs($cook);
        $this->get(at('kitchen'))->assertInertia(fn (Assert $page) => $page->has('tickets', 0));
    });

    it('flags the ticket of a cancelled order for the cook to clear, and clears only that', function () {
        member(['restaurant.orders.take', 'restaurant.orders.void']);
        $cancelled = tableOrder([[$this->beans, 1]]);
        $this->post(at("orders/{$cancelled->id}/cancel"), ['reason' => 'Guests left'])->assertSessionHasNoErrors();
        $this->post(at('orders'), ['service' => 'takeaway'])->assertSessionHasNoErrors();
        $live = $this->asTenant('acme', fn () => Order::query()->where('service', 'takeaway')->sole());
        $this->post(at("orders/{$live->id}/lines"), ['lines' => [['item_id' => $this->beans->id, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->post(at("orders/{$live->id}/send"))->assertSessionHasNoErrors();
        [$gone, $cooking] = $this->asTenant('acme', fn () => [
            KitchenTicket::query()->where('order_id', $cancelled->id)->sole(),
            KitchenTicket::query()->where('order_id', $live->id)->sole(),
        ]);

        member(['restaurant.kitchen.view']);
        $this->get(at('kitchen'))->assertInertia(fn (Assert $page) => $page
            ->has('tickets', 2)
            ->where('tickets.0.cancelled', true)
            ->where('tickets.1.cancelled', false));

        $this->post(at("kitchen/{$cooking->id}/clear"))->assertSessionHasErrors('ticket');
        $this->post(at("kitchen/{$gone->id}/clear"))->assertSessionHasNoErrors();
        $this->post(at("kitchen/{$gone->id}/clear"))->assertSessionHasErrors('ticket');

        expect($this->asTenant('acme', fn () => $gone->fresh()->status))->toBe('cleared');
        $this->get(at('kitchen'))->assertInertia(fn (Assert $page) => $page->has('tickets', 1)->where('tickets.0.id', $cooking->id));
    });
});

describe('the bill', function () {
    it('pays the bill into the till: dishes without stock, drinks from theirs, Finance told', function () {
        member(['restaurant.orders.take', 'restaurant.bills.take', 'restaurant.bills.view']);
        $order = tableOrder([[$this->beans, 2], [$this->soda, 1], [$this->soda, 2, 'cold']]);

        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'amount_tendered' => '50.00'])
            ->assertRedirect(at("orders/{$order->id}"))
            ->assertSessionHasNoErrors();

        $bill = $this->asTenant('acme', fn () => Bill::query()->with('lines')->sole());
        expect($bill->number)->toBe('B-000001')
            ->and($bill->total_minor)->toBe(3900)
            ->and($bill->change_minor)->toBe(1100)
            ->and($bill->cost_minor)->toBe(450)
            ->and($bill->lines->firstWhere('menu_item_id', $this->soda->id)->quantity)->toBe(3)
            ->and(sodasInJuba())->toBe(21);

        $order = $this->asTenant('acme', fn () => $order->fresh());
        expect($order->status)->toBe('paid');

        expect(financeEvents())->toBe(['finance.invoice.issued', 'finance.payment.received']);
        $invoice = Envelope::fromArray($this->platform()->moduleEvents['finance'][0]);
        expect($invoice->sourceApp)->toBe('restaurant')
            ->and($invoice->payload['invoice_number'])->toBe('B-000001')
            ->and($invoice->payload['total_minor'])->toBe(3900)
            ->and(collect($invoice->payload['lines'])->pluck('cost_minor', 'description')->all())->toBe(['Rice and beans' => 0, 'Soda' => 450]);
        $payment = Envelope::fromArray($this->platform()->moduleEvents['finance'][1]);
        expect($payment->payload['method'])->toBe('cash')->and($payment->payload['amount_minor'])->toBe(3900);

        $this->get(at("bills/{$bill->id}"))->assertInertia(fn (Assert $page) => $page
            ->component('bills/show')
            ->where('bill.changeMinor', 1100)
            ->has('bill.lines', 2));

        // Paid: it never changes, and is never paid twice.
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash'])->assertSessionHasErrors('payment_method');
        $this->post(at("orders/{$order->id}/lines"), ['lines' => [['item_id' => $this->soda->id, 'quantity' => 1]]])->assertSessionHasErrors('lines');
        expect($this->asTenant('acme', fn () => Bill::query()->count()))->toBe(1);
    });

    it('never bills what the kitchen has not been sent, nor a voided line', function () {
        member(['restaurant.orders.take', 'restaurant.orders.void', 'restaurant.bills.take']);
        $order = tableOrder([[$this->beans, 1], [$this->soda, 1]]);
        $this->post(at("orders/{$order->id}/lines"), ['lines' => [['item_id' => $this->beans->id, 'quantity' => 1]]]);

        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash'])->assertSessionHasErrors('payment_method');

        $this->post(at("orders/{$order->id}/send"));
        $soda = $this->asTenant('acme', fn () => OrderLine::query()->where('menu_item_id', $this->soda->id)->sole());
        $this->post(at("orders/{$order->id}/lines/{$soda->id}/void"), ['reason' => 'Spilt']);
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'mobile_money', 'payment_reference' => 'MM-1'])->assertSessionHasNoErrors();

        $bill = $this->asTenant('acme', fn () => Bill::query()->with('lines')->sole());
        expect($bill->total_minor)->toBe(3000)
            ->and($bill->payment_reference)->toBe('MM-1')
            ->and($bill->lines->pluck('menu_item_id')->all())->toBe([$this->beans->id])
            ->and(sodasInJuba())->toBe(24);
    });

    it('needs the cashier\'s till open, and enough cash', function () {
        member();
        $order = tableOrder([[$this->beans, 1]]);
        // A cashier who has not started a shift.
        member(['restaurant.orders.take', 'restaurant.bills.take'], User::factory()->create());

        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash'])->assertSessionHasErrors('payment_method');

        $this->post(at('tills'), ['opening_float' => '0'])->assertSessionHasNoErrors();
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'amount_tendered' => '10.00'])->assertSessionHasErrors('amount_tendered');
        expect($this->asTenant('acme', fn () => $order->fresh()->status))->toBe('open')
            ->and(financeEvents())->toBe([]);
    });

    it('bills drinks served beyond what the branch holds, taking stock below zero for a count', function () {
        member(['restaurant.orders.take', 'restaurant.bills.take', 'restaurant.stock.view']);
        $order = tableOrder([[$this->soda, 25]]);

        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'card'])->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => $order->fresh()->status))->toBe('paid')
            ->and(sodasInJuba())->toBe(-1);
        $this->get(at('stock'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.onHand', -1)
            ->where('rows.0.short', true));
    });

    it('shows a bad day as a validation error', function () {
        member(['restaurant.orders.take', 'restaurant.bills.view']);

        $this->get(at('orders?day=not-a-day'))->assertSessionHasErrors('day');
        $this->get(at('bills?day=2026-13-45'))->assertSessionHasErrors('day');
        $this->get(at('orders?day=2026-10-30'))->assertOk();
    });

    it('voids a paid bill: cash back from the voider\'s till, the drinks back in stock', function () {
        member(['restaurant.orders.take', 'restaurant.bills.take', 'restaurant.bills.view', 'restaurant.bills.void']);
        $order = tableOrder([[$this->beans, 1], [$this->soda, 2]]);
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash']);
        $bill = $this->asTenant('acme', fn () => Bill::query()->sole());

        $this->post(at("bills/{$bill->id}/void"), ['reason' => 'Wrong table'])->assertSessionHasNoErrors();
        $this->post(at("bills/{$bill->id}/void"), ['reason' => 'Again'])->assertSessionHasErrors('reason');

        $bill = $this->asTenant('acme', fn () => $bill->fresh());
        expect($bill->status)->toBe(Bill::VOIDED)
            ->and($bill->refund_till_shift_id)->toBe($bill->till_shift_id)
            ->and(sodasInJuba())->toBe(24)
            ->and(financeEvents())->toBe(['finance.invoice.issued', 'finance.payment.received', 'finance.invoice.voided']);
        $void = Envelope::fromArray($this->platform()->moduleEvents['finance'][2]);
        expect($void->payload['refund'])->toBe(['method' => 'cash', 'amount_minor' => 2100, 'payment_reference' => 'B-000001']);

        $this->get(at('bills'))->assertInertia(fn (Assert $page) => $page
            ->component('bills/index')
            ->has('bills', 1)
            ->where('takings.totalMinor', 0));
    });
});

describe('split bills and discounts', function () {
    it('splits by items: each part its own bill, the order closed by the last', function () {
        member(['restaurant.orders.take', 'restaurant.orders.void', 'restaurant.bills.take']);
        $order = tableOrder([[$this->beans, 2], [$this->soda, 1]]);
        [$beans, $soda] = $this->asTenant('acme', fn () => [
            OrderLine::query()->where('menu_item_id', $this->beans->id)->sole(),
            OrderLine::query()->where('menu_item_id', $this->soda->id)->sole(),
        ]);

        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'line_ids' => [(string) Str::uuid()]])->assertSessionHasErrors('line_ids');
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'card', 'line_ids' => [$soda->id]])->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => $order->fresh()->status))->toBe('open')
            ->and(sodasInJuba())->toBe(23);
        $this->get(at("orders/{$order->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('bill.total_minor', 3000)
            ->where('bill.paid_minor', 300)
            ->has('bill.lines_open', 1)
            ->where('order.bills.0.totalMinor', 300));

        // Paid lines stay paid: no void, no cancel, no paying them again.
        $this->post(at("orders/{$order->id}/lines/{$soda->id}/void"), ['reason' => 'Spilt'])->assertSessionHasErrors('line');
        $this->post(at("orders/{$order->id}/cancel"), ['reason' => 'Left'])->assertSessionHasErrors('reason');
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'line_ids' => [$soda->id]])->assertSessionHasErrors('line_ids');

        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'line_ids' => [$beans->id], 'amount_tendered' => '30.00'])->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => $order->fresh()->status))->toBe('paid')
            ->and($this->asTenant('acme', fn () => Bill::query()->orderBy('number')->pluck('total_minor')->all()))->toBe([300, 3000])
            ->and(financeEvents())->toBe(['finance.invoice.issued', 'finance.payment.received', 'finance.invoice.issued', 'finance.payment.received']);
        $this->get(at('orders'))->assertInertia(fn (Assert $page) => $page
            ->where('orders.0.bill.number', 'B-000001 +1')
            ->where('orders.0.bill.totalMinor', 3300));
    });

    it('splits in equal shares of what is left, the last taking the rest and the drinks', function () {
        member(['restaurant.orders.take', 'restaurant.bills.take', 'restaurant.bills.void', 'restaurant.bills.view']);
        // 2 × 15.00 + 3.00 + a 0.01 tea: 33.01, which thirds do not divide.
        $tea = $this->asTenant('acme', fn () => MenuItem::factory()->create(['name' => 'Tea', 'price_minor' => 1]));
        $order = tableOrder([[$this->beans, 2], [$this->soda, 1], [$tea, 1]]);

        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'parts' => 3])->assertSessionHasNoErrors();
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'parts' => 4])->assertSessionHasErrors('parts');
        $this->get(at("orders/{$order->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('bill.total_minor', 2201)
            ->where('bill.shares.paid', 1)
            ->where('bill.shares.next_minor', 1100));
        expect(sodasInJuba())->toBe(24);

        // A share given back while the order is open is owed again.
        $first = $this->asTenant('acme', fn () => Bill::query()->sole());
        $this->post(at("bills/{$first->id}/void"), ['reason' => 'Paid twice'])->assertSessionHasNoErrors();
        $this->get(at("orders/{$order->id}"))->assertInertia(fn (Assert $page) => $page->where('bill.total_minor', 3301)->where('bill.shares', null));

        foreach ([1, 2, 3] as $part) {
            $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'card', 'parts' => 3])->assertSessionHasNoErrors();
        }

        $shares = $this->asTenant('acme', fn () => Bill::query()->where('status', 'paid')->orderBy('number')->with('lines')->get());
        expect($shares->pluck('total_minor')->all())->toBe([1100, 1100, 1101])
            ->and($shares->pluck('part')->all())->toBe([1, 2, 3])
            ->and($shares->last()->lines->sole()->name)->toBe('Share 3 of 3')
            ->and($shares->last()->cost_minor)->toBe(150)
            ->and($shares->first()->cost_minor)->toBe(0)
            ->and(sodasInJuba())->toBe(23)
            ->and($this->asTenant('acme', fn () => $order->fresh()->status))->toBe('paid')
            ->and($this->asTenant('acme', fn () => OrderLine::query()->whereNull('bill_id')->count()))->toBe(0);

        $invoice = collect($this->platform()->moduleEvents['finance'])->map(fn (array $e) => Envelope::fromArray($e))
            ->filter(fn (Envelope $e) => $e->eventType === 'finance.invoice.issued')->last();
        expect($invoice->payload['lines'][0]['description'])->toBe("Share 3 of 3, order {$order->fresh()->number}")
            ->and($invoice->payload['lines'][0]['cost_minor'])->toBe(150);
    });

    it('takes the rest of the shares at once', function () {
        member(['restaurant.orders.take', 'restaurant.bills.take', 'restaurant.bills.view']);
        $order = tableOrder([[$this->beans, 2]]);

        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'parts' => 3])->assertSessionHasNoErrors();
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'line_ids' => [$this->asTenant('acme', fn () => OrderLine::query()->value('id'))]])
            ->assertSessionHasErrors('line_ids');
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash'])->assertSessionHasNoErrors();

        $rest = $this->asTenant('acme', fn () => Bill::query()->orderByDesc('number')->with('lines')->first());
        expect($rest->total_minor)->toBe(2000)
            ->and($rest->lines->sole()->name)->toBe('Shares 2 to 3 of 3')
            ->and($this->asTenant('acme', fn () => $order->fresh()->status))->toBe('paid');
    });

    it('discounts a bill with a reason, only with the right to, and tells Finance', function () {
        member(['restaurant.orders.take', 'restaurant.bills.take']);
        $order = tableOrder([[$this->beans, 2], [$this->soda, 1]]);
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'discount' => '10', 'discount_type' => 'percent', 'discount_reason' => 'Regular'])->assertForbidden();

        $cashier = member(['restaurant.orders.take', 'restaurant.bills.take', 'restaurant.bills.discount', 'restaurant.bills.view']);
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'discount' => '10', 'discount_type' => 'percent'])->assertSessionHasErrors('discount_reason');
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'discount' => '40.00', 'discount_reason' => 'Regular'])->assertSessionHasErrors('discount');
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash', 'discount' => '10%', 'discount_type' => 'percent', 'discount_reason' => 'Regular', 'amount_tendered' => '29.70'])
            ->assertSessionHasNoErrors();

        $bill = $this->asTenant('acme', fn () => Bill::query()->sole());
        expect($bill->subtotal_minor)->toBe(3300)
            ->and($bill->discount_minor)->toBe(330)
            ->and($bill->total_minor)->toBe(2970)
            ->and($bill->change_minor)->toBe(0)
            ->and($bill->discount_reason)->toBe('Regular');

        $invoice = Envelope::fromArray($this->platform()->moduleEvents['finance'][0]);
        expect($invoice->payload['total_minor'])->toBe(2970)
            ->and(array_sum(array_column($invoice->payload['lines'], 'amount_minor')))->toBe(2970)
            ->and(collect($invoice->payload['lines'])->last()['description'])->toBe('Discount: Regular');

        $this->get(at("bills/{$bill->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('bill.subtotalMinor', 3300)
            ->where('bill.discountMinor', 330));
        $till = $this->asTenant('acme', fn () => TillShift::query()->where('opened_by', $cashier->id)->sole());
        expect($this->asTenant('acme', fn () => app(Tills::class)->summary($till)['takenMinor']['cash']))->toBe(2970);
    });
});

describe('the day report', function () {
    it('shows the day: takings by method and person, what sold, discounts, refunds, voids and cancellations', function () {
        member(['restaurant.orders.take', 'restaurant.bills.take']);
        $this->get(at('reports'))->assertForbidden();

        member(['restaurant.orders.take', 'restaurant.orders.void', 'restaurant.bills.take', 'restaurant.bills.discount', 'restaurant.bills.void', 'restaurant.reports.view']);

        $first = tableOrder([[$this->beans, 2], [$this->soda, 1]]);
        $soda = $this->asTenant('acme', fn () => OrderLine::query()->where('menu_item_id', $this->soda->id)->sole());
        $this->post(at("orders/{$first->id}/lines/{$soda->id}/void"), ['reason' => 'Spilt'])->assertSessionHasNoErrors();
        $this->post(at("orders/{$first->id}/pay"), ['payment_method' => 'cash', 'discount' => '10', 'discount_type' => 'percent', 'discount_reason' => 'Regular'])->assertSessionHasNoErrors();

        $second = tableOrder([[$this->beans, 1]]);
        $this->post(at("orders/{$second->id}/pay"), ['payment_method' => 'card'])->assertSessionHasNoErrors();
        $refunded = $this->asTenant('acme', fn () => Bill::query()->where('order_id', $second->id)->sole());
        $this->post(at("bills/{$refunded->id}/void"), ['reason' => 'Wrong table'])->assertSessionHasNoErrors();

        $third = tableOrder([[$this->soda, 2]]);
        $this->post(at("orders/{$third->id}/cancel"), ['reason' => 'Walked out'])->assertSessionHasNoErrors();

        $this->get(at('reports'))->assertInertia(fn (Assert $page) => $page
            ->component('reports/day')
            ->where('takings.count', 1)
            ->where('takings.totalMinor', 2700)
            ->where('takings.averageMinor', 2700)
            ->where('takings.discountMinor', 300)
            ->where('takings.refundedMinor', 1500)
            ->has('byMethod', 1)
            ->where('byMethod.0.name', 'Cash')
            ->where('byMethod.0.totalMinor', 2700)
            ->has('byCashier', 1)
            ->has('byWaiter', 1)
            ->has('bestSellers', 1)
            ->where('bestSellers.0.quantity', 2)
            ->where('discounts.0.reason', 'Regular')
            ->where('voidedBills.0.reason', 'Wrong table')
            ->where('cancelledOrders.0.reason', 'Walked out')
            ->has('voidedLines', 1)
            ->where('voidedLines.0.reason', 'Spilt'));

        $this->get(at('reports?day=2020-01-01'))->assertInertia(fn (Assert $page) => $page->where('takings.count', 0)->has('voidedLines', 0));
    });
});

describe('tills', function () {
    it('closes a shift with a count, telling Finance only of a difference', function () {
        $cashier = member(['restaurant.orders.take', 'restaurant.bills.take']);
        $order = tableOrder([[$this->beans, 2]]);
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash']);
        $till = $this->asTenant('acme', fn () => TillShift::query()->where('opened_by', $cashier->id)->sole());

        $this->post(at('tills'), ['opening_float' => '5.00'])->assertSessionHasErrors('opening_float');
        $this->get(at("tills/{$till->id}"))->assertInertia(fn (Assert $page) => $page
            ->component('tills/show')
            ->where('summary.billsCount', 1)
            ->where('summary.expectedCashMinor', 4000));

        // Short by 1.00: a note is needed, and Finance hears of it.
        $this->post(at("tills/{$till->id}/close"), ['counted_cash' => '39.00'])->assertSessionHasErrors('closing_note');
        $this->post(at("tills/{$till->id}/close"), ['counted_cash' => '39.00', 'closing_note' => 'Change given twice'])->assertSessionHasNoErrors();

        $till = $this->asTenant('acme', fn () => $till->fresh());
        expect($till->status)->toBe('closed')
            ->and($till->expected_cash_minor)->toBe(4000)
            ->and(financeEvents())->toContain('finance.cash.counted');
        $counted = collect($this->platform()->moduleEvents['finance'])->map(fn (array $event) => Envelope::fromArray($event))->firstWhere('eventType', 'finance.cash.counted');
        expect($counted->payload['difference_minor'])->toBe(-100);
    });

    it('shows a cashier only their own shifts, a manager everyone\'s', function () {
        $cashier = member(['restaurant.bills.take']);
        $till = $this->asTenant('acme', fn () => TillShift::query()->where('opened_by', $cashier->id)->sole());

        member(['restaurant.bills.take']);
        $this->get(at("tills/{$till->id}"))->assertNotFound();
        $this->get(at('tills'))->assertInertia(fn (Assert $page) => $page->has('tills', 1)->where('managing', false));

        member(['restaurant.tills.manage']);
        $this->get(at("tills/{$till->id}"))->assertOk();
        $this->get(at('tills'))->assertInertia(fn (Assert $page) => $page->has('tills', 2)->where('managing', true));
    });
});

describe('stock', function () {
    it('keeps stock of drinks, never of dishes', function () {
        member(['restaurant.stock.view', 'restaurant.stock.adjust']);

        $this->get(at('stock'))->assertInertia(fn (Assert $page) => $page
            ->component('stock/index')
            ->has('rows', 1)
            ->where('rows.0.name', 'Soda')
            ->where('rows.0.onHand', 24));
        $this->get(at("stock/{$this->beans->id}"))->assertNotFound();
        $this->post(at("stock/{$this->beans->id}/opening"), ['quantity' => 5, 'unit_cost' => '1.00'])->assertSessionHasErrors('quantity');
    });

    it('adds opening stock at its cost and counts at the average, telling Finance', function () {
        member(['restaurant.stock.view', 'restaurant.stock.adjust']);

        // 24 at 1.50 and 8 at 2.30: 32 at 1.70.
        $this->post(at("stock/{$this->soda->id}/opening"), ['quantity' => 8, 'unit_cost' => '2.30'])->assertSessionHasNoErrors();
        $this->post(at("stock/{$this->soda->id}/adjustments"), ['change' => 2, 'reason' => 'damaged'])->assertSessionHasErrors('change');
        $this->post(at("stock/{$this->soda->id}/adjustments"), ['change' => -40, 'reason' => 'count'])->assertSessionHasErrors('change');
        $this->post(at("stock/{$this->soda->id}/adjustments"), ['change' => -2, 'reason' => 'damaged', 'note' => 'Dropped'])->assertSessionHasNoErrors();

        $level = $this->asTenant('acme', fn () => StockLevel::query()->where('branch_id', $this->juba)->sole());
        expect($level->quantity)->toBe(30)->and($level->average_cost_minor)->toBe(170);

        $adjusted = array_map(fn (array $event) => Envelope::fromArray($event)->payload, $this->platform()->moduleEvents['finance']);
        expect(array_column($adjusted, 'kind'))->toBe(['opening', 'write_off'])
            ->and(array_column($adjusted, 'amount_minor'))->toBe([1840, -340]);

        $this->get(at("stock/{$this->soda->id}"))->assertInertia(fn (Assert $page) => $page
            ->component('stock/show')
            ->where('item.onHand', 30)
            ->has('movements', 3)
            ->where('movements.0.reason', 'Damaged or spilt'));
    });

    it('receives a supplier delivery: stock up at its cost, Finance told what is owed', function () {
        $water = $this->asTenant('acme', fn () => MenuItem::factory()->drink()->create(['name' => 'Water', 'price_minor' => 200]));
        member(['restaurant.stock.view']);
        $this->get(at('stock/deliveries/create'))->assertForbidden();
        $this->get(at('stock/deliveries'))->assertOk();

        member(['restaurant.stock.view', 'restaurant.stock.receive']);
        $this->get(at('stock/deliveries/create'))->assertInertia(fn (Assert $page) => $page
            ->component('stock/deliveries/create')
            ->has('drinks', 2)
            ->where('drinks.0.name', 'Soda')
            ->where('drinks.0.averageCostMinor', 150));

        // A dish keeps no stock; the same drink twice is one line.
        $this->post(at('stock/deliveries'), ['supplier_name' => 'Juba Drinks', 'lines' => [['item_id' => $this->beans->id, 'quantity' => 1, 'unit_cost' => '1.00']]])
            ->assertSessionHasErrors('lines');
        $this->post(at('stock/deliveries'), ['supplier_name' => 'Juba Drinks', 'lines' => [
            ['item_id' => $this->soda->id, 'quantity' => 1, 'unit_cost' => '1.00'],
            ['item_id' => $this->soda->id, 'quantity' => 1, 'unit_cost' => '1.00'],
        ]])->assertSessionHasErrors('lines.0.item_id');

        // 24 at 1.50 and 8 at 2.30: 32 at 1.70; 12 waters at 1.00.
        $this->post(at('stock/deliveries'), [
            'supplier_name' => ' Juba Drinks ',
            'supplier_reference' => 'INV-77',
            'lines' => [
                ['item_id' => $this->soda->id, 'quantity' => 8, 'unit_cost' => '2.30'],
                ['item_id' => $water->id, 'quantity' => 12, 'unit_cost' => '1.00'],
            ],
        ])->assertSessionHasNoErrors();

        $delivery = $this->asTenant('acme', fn () => StockDelivery::query()->sole());
        expect($delivery->number)->toBe('DEL-000001')
            ->and($delivery->supplier_name)->toBe('Juba Drinks')
            ->and($delivery->total_minor)->toBe(3040);
        $level = $this->asTenant('acme', fn () => StockLevel::query()->where('branch_id', $this->juba)->where('menu_item_id', $this->soda->id)->sole());
        expect($level->quantity)->toBe(32)->and($level->average_cost_minor)->toBe(170);

        $events = array_map(fn (array $event) => Envelope::fromArray($event), $this->platform()->moduleEvents['finance']);
        expect(array_map(fn (Envelope $e) => $e->eventType, $events))->toBe(['finance.bill.received'])
            ->and($events[0]->payload['supplier_name'])->toBe('Juba Drinks')
            ->and($events[0]->payload['supplier_reference'])->toBe('INV-77')
            ->and($events[0]->payload['total_minor'])->toBe(3040)
            ->and(array_column($events[0]->payload['lines'], 'amount_minor'))->toBe([1840, 1200]);

        $this->get(at("stock/deliveries/{$delivery->id}"))->assertInertia(fn (Assert $page) => $page
            ->component('stock/deliveries/show')
            ->has('delivery.lines', 2)
            ->where('delivery.lines.0.name', 'Soda'));
        $this->get(at("stock/{$this->soda->id}"))->assertInertia(fn (Assert $page) => $page->where('movements.0.type', 'delivery'));
        $this->get(at('stock/deliveries/create'))->assertInertia(fn (Assert $page) => $page->where('suppliers', ['Juba Drinks']));

        // Another branch does not see it.
        $this->actingAsMember('acme', ['restaurant.stock.view'], branch: $this->wau);
        $this->get(at("stock/deliveries/{$delivery->id}"))->assertNotFound();
    });

    it('lists drinks running low on the home page', function () {
        $this->asTenant('acme', fn () => $this->soda->forceFill(['reorder_level' => 24])->save());
        member(['restaurant.stock.view', 'restaurant.orders.take']);

        $this->get(at('', $this->juba))->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('lowStock', 1)
            ->where('openOrders', 0)
            ->where('takings', null));
    });
});

/**
 * Signs in a Global Admin: every permission of the catalog's Global Admin,
 * across the organization.
 */
function globalAdmin(): User
{
    $catalog = json_decode((string) file_get_contents(base_path('platform/catalog.json')), true);
    $role = collect($catalog['roles'])->firstWhere('key', 'global_admin');
    $admin = test()->actingAsMember('acme', $role['permissions']);
    assert($admin instanceof User);

    return $admin;
}

describe('roles', function () {
    it('keeps Global Admin organization-wide, Manager to branches, and gives the Store Keeper stock only', function () {
        $catalog = json_decode((string) file_get_contents(base_path('platform/catalog.json')), true);
        $roles = collect($catalog['roles'])->keyBy('key');
        $permissions = collect($catalog['permissions'])->keyBy('key');

        expect($roles->keys()->all())->toBe(['global_admin', 'manager', 'cashier', 'waiter', 'store_keeper', 'kitchen'])
            ->and($roles['global_admin']['scopes'])->toBe(['organization'])
            ->and($roles['global_admin']['permissions'])->toEqualCanonicalizing($permissions->keys()->all())
            ->and($roles['manager']['scopes'])->toBe(['branch'])
            ->and($roles['manager']['permissions'])->not->toContain('restaurant.branches.overview', 'restaurant.access.manage', 'restaurant.menu.manage')
            ->and($roles['manager']['sensitivity'])->toBeLessThan($roles['global_admin']['sensitivity'])
            ->and($roles['store_keeper']['permissions'])->toEqualCanonicalizing(['restaurant.menu.view', 'restaurant.stock.view', 'restaurant.stock.receive', 'restaurant.stock.adjust'])
            ->and($roles['kitchen']['name'])->toBe('Kitchen Staff')
            ->and($permissions['restaurant.branches.overview']['scopes'])->toBe(['organization'])
            ->and($permissions['restaurant.access.manage']['scopes'])->toBe(['organization']);
    });

    it('lets the Store Keeper work the stock and nothing of service or money', function () {
        member(['restaurant.menu.view', 'restaurant.stock.view', 'restaurant.stock.receive', 'restaurant.stock.adjust']);

        $this->get(at('stock'))->assertOk();
        $this->get(at('stock/deliveries/create'))->assertOk();
        $this->get(at('floor'))->assertForbidden();
        $this->get(at('bills'))->assertForbidden();
        $this->get(at('reports'))->assertForbidden();
        $this->get(at('management'))->assertForbidden();
    });
});

describe('every branch together', function () {
    it('lands a Global Admin on every branch together, and keeps branch staff to their branch', function () {
        globalAdmin();
        $this->get(at())->assertRedirect(at('management'));
        $this->get(at('management'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('overview')
            ->where('overview', true)
            ->where('branch', null)
            ->has('performance', 2)
            ->has('branches', 2));

        // A manager of one branch, even holding the overview there, works in that branch.
        $manager = $this->actingAsMember('acme', ['restaurant.orders.take', 'restaurant.reports.view', 'restaurant.branches.overview'], branch: $this->juba);
        $this->get(at())->assertRedirect(at('', $this->juba));
        $this->get(at('', $this->juba))->assertOk()->assertInertia(fn (Assert $page) => $page->component('home')->where('overview', false)->where('branch.name', 'Juba'));
        $this->get(at('management'))->assertForbidden();
        $this->put(at('branch'), ['branch_id' => 'all'])->assertNotFound();

        // Organization-wide without the overview: still one branch at a time.
        $this->flushSession();
        $this->actingAsMember('acme', ['restaurant.orders.take']);
        $this->get(at())->assertRedirect(at('branch'));
        $this->get(at('management'))->assertForbidden();
    });

    it('opens every branch in its own workspace beside management, which stays the whole restaurant\'s', function () {
        globalAdmin();

        $this->get(at('management/branches'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('management/branches')
            ->where('area', 'management')
            ->where('branch', null)
            ->has('branches', 2)
            ->where('branches.0.name', 'Juba'));

        // Two branch tabs and the management tab, all in one session.
        $this->get(at('', $this->wau))->assertInertia(fn (Assert $page) => $page->component('home')->where('area', 'branch')->where('branch.name', 'Wau'));
        $this->get(at('', $this->juba))->assertInertia(fn (Assert $page) => $page->where('branch.name', 'Juba'));
        $this->get(at('kitchen', $this->wau))->assertInertia(fn (Assert $page) => $page->where('branch.name', 'Wau'));
        $this->get(at('management'))->assertInertia(fn (Assert $page) => $page->where('area', 'management')->where('branch', null)->has('performance', 2));
        $this->get(at())->assertRedirect(at('management'));

        // The old form still works, as a link would.
        $this->put(at('branch'), ['branch_id' => $this->wau])->assertRedirect(at('', $this->wau));
        $this->put(at('branch'), ['branch_id' => 'all'])->assertRedirect(at('management'));

        // Old addresses of management pages.
        $this->get(at('branches/overview'))->assertRedirect(at('management'));
        $this->get(at('staff'))->assertRedirect(at('management/staff'));
    });

    it('shows each drink in every branch, and keeps branch staff out of management', function () {
        $this->asTenant('acme', fn () => $this->soda->forceFill(['reorder_level' => 30])->save());

        globalAdmin();
        $this->get(at('management/inventory'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('management/inventory')
            ->has('branches', 2)
            ->where('drinks.0.name', 'Soda')
            ->where("drinks.0.byBranch.{$this->juba}", 24)
            ->where("drinks.0.byBranch.{$this->wau}", 0)
            ->where('drinks.0.valueMinor', 24 * 150));

        $this->actingAsMember('acme', ['restaurant.orders.take', 'restaurant.stock.view', 'restaurant.reports.view'], branch: $this->juba);
        foreach (['management', 'management/branches', 'management/inventory', 'management/staff'] as $page) {
            $this->get(at($page))->assertForbidden();
        }
    });

    it('adds up sales, discounts, refunds and payments the day report\'s way, branch by branch', function () {
        member(['restaurant.orders.take', 'restaurant.orders.void', 'restaurant.bills.take', 'restaurant.bills.discount', 'restaurant.bills.void']);
        // 2 beans and a soda, 10% off: 3300 - 330 = 2970, in cash.
        $first = tableOrder([[$this->beans, 2], [$this->soda, 1]]);
        $this->post(at("orders/{$first->id}/pay"), ['payment_method' => 'cash', 'discount' => '10', 'discount_type' => 'percent', 'discount_reason' => 'Regular'])->assertSessionHasNoErrors();
        // Beans by card, then refunded.
        $second = tableOrder([[$this->beans, 1]]);
        $this->post(at("orders/{$second->id}/pay"), ['payment_method' => 'card'])->assertSessionHasNoErrors();
        $refunded = $this->asTenant('acme', fn () => Bill::query()->where('order_id', $second->id)->sole());
        $this->post(at("bills/{$refunded->id}/void"), ['reason' => 'Wrong table'])->assertSessionHasNoErrors();
        // And one still open.
        tableOrder([[$this->beans, 1]]);

        globalAdmin();
        $this->get(at('management'))->assertInertia(fn (Assert $page) => $page
            ->where('totals.grossMinor', 4800)
            ->where('totals.discountMinor', 330)
            ->where('totals.salesMinor', 4470)
            ->where('totals.refundedMinor', 1500)
            ->where('totals.netMinor', 2970)
            ->where('totals.cashMinor', 2970)
            ->where('totals.nonCashMinor', 1500)
            ->where('totals.bills', 1)
            ->where('totals.averageOrderMinor', 2970)
            ->where('totals.orders.total', 3)
            ->where('totals.orders.completed', 2)
            ->where('totals.orders.pending', 1)
            ->where('totals.orders.refunded', 1)
            ->where('performance.0.name', 'Juba')
            ->where('performance.0.netMinor', 2970)
            ->where('performance.0.openOrders', 1)
            ->where('performance.0.status', 'trading')
            ->where('performance.1.name', 'Wau')
            ->where('performance.1.netMinor', 0)
            ->where('performance.1.status', 'quiet')
            ->has('trend', 1)
            ->where('trend.0.netMinor', 2970)
            ->where('orders.total', 3));

        // One branch, one status.
        $this->get(at("management?branch={$this->wau}"))->assertInertia(fn (Assert $page) => $page
            ->where('totals.netMinor', 0)->has('performance', 1)->where('orders.total', 0));
        $this->get(at('management?status=paid'))->assertInertia(fn (Assert $page) => $page->where('orders.total', 2));

        // The period before had nothing; the day before shows nothing.
        $this->get(at('management?from=2026-10-24&to=2026-10-24'))->assertInertia(fn (Assert $page) => $page
            ->where('totals.netMinor', 0)->where('totals.orders.total', 0));
    });

    it('never adds a bill in another currency into the totals', function () {
        member(['restaurant.orders.take', 'restaurant.bills.take']);
        $order = tableOrder([[$this->beans, 1]]);
        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->asTenant('acme', fn () => Bill::query()->sole()->update(['currency' => 'USD']));

        globalAdmin();
        $this->get(at('management'))->assertInertia(fn (Assert $page) => $page
            ->where('totals.netMinor', 0)
            ->where('otherCurrencies.USD', 1));
    });

    it('refuses a branch that is not the member\'s, another workspace\'s included, and too long a period', function () {
        $elsewhere = $this->platform()->branch('globex', 'Malakal');

        globalAdmin();
        $this->get(at("management?branch={$elsewhere}"))->assertSessionHasErrors('branch');
        $this->get(at('management?branch='.Str::uuid()))->assertSessionHasErrors('branch');
        $this->put(at('branch'), ['branch_id' => $elsewhere])->assertNotFound();
        $this->get(at('management?from=2026-01-01&to=2026-10-25'))->assertSessionHasErrors('from');
        $this->get(at('management?from=2026-10-25&to=2026-10-01'))->assertSessionHasErrors('to');
    });

    it('shows the kitchens now and what needs attention', function () {
        member(['restaurant.orders.take', 'restaurant.bills.take']);
        tableOrder([[$this->beans, 1]]);
        $this->asTenant('acme', fn () => MenuItem::query()->whereKey($this->soda->id)->update(['reorder_level' => 30]));

        $this->travel(45)->minutes();
        globalAdmin();
        $this->get(at('management'))->assertInertia(fn (Assert $page) => $page
            ->where('kitchen.preparing', 1)
            ->where('kitchen.slow', 1)
            ->where('kitchen.oldestMinutes', 45)
            ->where('performance.0.pendingTickets', 1)
            ->where('performance.0.lowStock', 1)
            ->where('performance.1.lowStock', 1)
            ->where('alerts', fn ($alerts) => collect($alerts)->pluck('kind')->sort()->values()->all() === ['kitchen', 'stock', 'stock']));

        // A till left open overnight.
        $this->travel(1)->days();
        $this->get(at('management'))->assertInertia(fn (Assert $page) => $page
            ->where('alerts', fn ($alerts) => collect($alerts)->contains('kind', 'till')));
    });
});

describe('staff and access', function () {
    it('shows who works where and whether they may sign in, to the restaurant\'s administrators only', function () {
        $organization = $this->platform()->organization('acme');
        $this->platform()->applicationMembers['acme'] = [
            ['membership_id' => (string) Str::uuid(), 'sub' => (string) Str::uuid(), 'name' => 'Achol', 'sign_in_name' => 'achol@example.test', 'membership_status' => 'active', 'is_owner' => false, 'application_access' => 'suspended', 'roles' => [
                ['role' => 'cashier', 'name' => 'Cashier', 'scope_type' => 'branch', 'branch_id' => $this->wau, 'expires_at' => null],
            ]],
            ['membership_id' => (string) Str::uuid(), 'sub' => (string) Str::uuid(), 'name' => 'Deng', 'sign_in_name' => 'deng', 'membership_status' => 'active', 'is_owner' => false, 'application_access' => 'active', 'roles' => [
                ['role' => 'global_admin', 'name' => 'Global Admin', 'scope_type' => 'organization', 'branch_id' => null, 'expires_at' => null],
            ]],
        ];
        expect($organization['id'])->not->toBe('');

        member(['restaurant.orders.take', 'restaurant.reports.view']);
        $this->get(at('management/staff'))->assertForbidden();
        $this->actingAsMember('acme', ['restaurant.access.manage'], branch: $this->juba);
        $this->get(at('management/staff'))->assertForbidden();

        globalAdmin();
        $this->get(at('management/staff'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('staff/index')
            ->where('manageUrl', '/manage/access')
            ->has('people', 2)
            ->where('people.0.signIn', 'suspended')
            ->where('people.0.roles.0.where', 'Wau')
            ->where('people.1.roles.0.where', 'All branches'));
    });

    it('stops a member at once when their sign-in is disabled, mid-session', function () {
        $user = member();
        $this->get(at('floor'))->assertOk();

        // The platform answers "not allowed" and tells the restaurant (access.changed).
        $this->platform()->grant('acme', (string) $user->getAuthIdentifier(), [['permissions' => ['restaurant.orders.take'], 'scope' => ['scope_type' => 'branch', 'branch_id' => $this->juba]]], allowed: false);
        app(PlatformApi::class)->forget($this->platform()->organization('acme')['id']);

        $this->get(at('floor'))->assertForbidden();
        $this->post(at('orders'), ['service' => 'takeaway'])->assertForbidden();
    });
});
