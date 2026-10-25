<?php

use App\Actions\Stock;
use App\Models\Bill;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockLevel;
use App\Models\TillShift;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
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
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

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
        test()->post(test()->workspaceUrl('acme', 'tills'), ['opening_float' => '10.00'])->assertSessionHasNoErrors();
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
    test()->post(test()->workspaceUrl('acme', 'orders'), ['service' => 'dine_in', 'dining_table_id' => test()->table->id, 'guests' => 2])->assertSessionHasNoErrors();
    $order = test()->asTenant('acme', fn () => Order::query()->where('dining_table_id', test()->table->id)->where('status', 'open')->sole());

    test()->post(test()->workspaceUrl('acme', "orders/{$order->id}/lines"), [
        'lines' => array_map(fn (array $line) => ['item_id' => $line[0]->id, 'quantity' => $line[1], 'note' => $line[2] ?? null], $lines),
    ])->assertSessionHasNoErrors();
    if ($send) {
        test()->post(test()->workspaceUrl('acme', "orders/{$order->id}/send"))->assertSessionHasNoErrors();
    }

    return $order;
}

function at(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path);
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
    it('asks which branch to work in when there are several', function () {
        $this->actingAsMember('acme', ['restaurant.orders.take']);

        $this->get(at('floor'))->assertRedirect(at('branch'));
        $this->get(at('branch'))->assertInertia(fn (Assert $page) => $page->component('branch')->has('branches', 2));

        $this->put(at('branch'), ['branch_id' => $this->wau])->assertRedirect(at('floor'));
        $this->get(at('floor'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('branch.name', 'Wau')->has('tables', 0));
    });

    it('opens only the branches the member has a role in', function () {
        member();

        $this->get(at('floor'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('branch.name', 'Juba'));
        $this->put(at('branch'), ['branch_id' => $this->wau])->assertNotFound();
    });

    it('counts a role only in its own branch', function () {
        $user = $this->actingAsMember('acme', ['restaurant.tables.manage'], branch: $this->wau);
        // Takes orders in Juba, sets up tables only in Wau.
        $this->platform()->grant('acme', (string) $user->getAuthIdentifier(), [
            ['permissions' => ['restaurant.orders.take'], 'scope' => ['scope_type' => 'branch', 'branch_id' => $this->juba]],
            ['permissions' => ['restaurant.tables.manage'], 'scope' => ['scope_type' => 'branch', 'branch_id' => $this->wau]],
        ]);
        $this->put(at('branch'), ['branch_id' => $this->juba]);

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

    it('refuses a bill for more drinks than the branch holds, and keeps the order open', function () {
        member(['restaurant.orders.take', 'restaurant.bills.take']);
        $order = tableOrder([[$this->soda, 25]]);

        $this->post(at("orders/{$order->id}/pay"), ['payment_method' => 'card'])->assertSessionHasErrors('payment_method');

        expect($this->asTenant('acme', fn () => Bill::query()->count()))->toBe(0)
            ->and($this->asTenant('acme', fn () => $order->fresh()->status))->toBe('open')
            ->and(sodasInJuba())->toBe(24);
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

    it('lists drinks running low on the home page', function () {
        $this->asTenant('acme', fn () => $this->soda->forceFill(['reorder_level' => 24])->save());
        member(['restaurant.stock.view', 'restaurant.orders.take']);

        $this->get(at())->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('lowStock', 1)
            ->where('openOrders', 0)
            ->where('takings', null));
    });
});
