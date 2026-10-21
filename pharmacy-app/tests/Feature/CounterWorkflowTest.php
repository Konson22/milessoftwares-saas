<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Actions\Sell;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\CounterOrder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PrintedDocument;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Panadol', 'sell_price_minor' => 250]));
    $this->amox = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Amoxil', 'prescription_only' => true, 'sell_price_minor' => 1200]));
    $this->regular = $this->asTenant('acme', fn () => Customer::query()->create(['application' => 'pharmacy', 'number' => 'C-90001', 'name' => 'Achol Deng', 'phone' => '+211 912 000 111']));
    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($keeper) {
        app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2026-12-31'), 3, 90, $keeper);
        app(Stock::class)->add($this->para->item, $this->juba, 'P-2', CarbonImmutable::parse('2027-12-31'), 20, 100, $keeper);
        app(Stock::class)->add($this->amox->item, $this->juba, 'A-1', CarbonImmutable::parse('2027-06-30'), 10, 600, $keeper);
    });
});

/**
 * The Juba counter's settings.
 *
 * @param  array<string, mixed>  $settings
 */
function counterSettings(array $settings): void
{
    test()->asTenant('acme', fn () => BranchCounterSettings::query()->updateOrCreate(
        ['branch_id' => test()->juba, 'application' => 'pharmacy'],
        $settings,
    ));
}

/**
 * Signs in a member of the Juba counter; cashiers get their till open.
 *
 * @param  list<string>  $permissions
 */
function counterStaff(array $permissions, bool $till = false): User
{
    $user = test()->actingAsMember('acme', $permissions, branch: test()->juba);
    if ($till) {
        test()->openTill($user, test()->juba);
    }

    return $user;
}

/**
 * @param  array<string, mixed>  $customer
 */
function takeOrder(int $quantity = 4, array $customer = [], ?string $key = null)
{
    return test()->post(test()->workspaceUrl('acme', 'orders'), [
        'items' => [['item_id' => test()->para->id, 'quantity' => $quantity]],
        'submission_id' => $key,
        ...$customer,
    ]);
}

function payOrder(CounterOrder $order, array $extra = [])
{
    return test()->post(test()->workspaceUrl('acme', "orders/{$order->id}/pay"), ['payment_method' => 'cash', 'amount_tendered' => '50.00', ...$extra]);
}

function handOverOrder(CounterOrder $order)
{
    return test()->post(test()->workspaceUrl('acme', "orders/{$order->id}/hand-over"));
}

function theOrder(): CounterOrder
{
    return test()->asTenant('acme', fn () => CounterOrder::query()->latest('ordered_at')->firstOrFail());
}

/**
 * Units of Panadol that left the shelf, all movements counted.
 */
function panadolTaken(): int
{
    return -(int) test()->asTenant('acme', fn () => StockMovement::query()->where('item_id', test()->para->id)->where('type', '!=', 'receipt')->sum('quantity'));
}

dataset('customers', [
    'walk-in' => [[], null, null, null],
    'named' => [['customer_name' => 'Mary Akol', 'customer_phone' => '0912 345 678'], 'Mary Akol', '0912 345 678', false],
    'named without phone' => [['customer_name' => 'John Garang'], 'John Garang', null, false],
    'registered' => [fn () => ['customer_id' => test()->regular->id], 'Achol Deng', '+211 912 000 111', true],
]);

describe('a direct sale', function () {
    it('records the customer the counter gives, or none', function (array|Closure $customer, ?string $name, ?string $phone, ?bool $registered) {
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view'], till: true);
        $customer = $customer instanceof Closure ? $customer() : $customer;

        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 2]],
            'payment_method' => 'cash',
            'amount_tendered' => '10.00',
            ...$customer,
        ])->assertSessionHasNoErrors();

        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
        expect($sale->customer_name)->toBe($name)
            ->and($sale->customer_phone)->toBe($phone)
            ->and($sale->customer_id)->toBe($registered ? $this->regular->id : null);

        $this->get($this->workspaceUrl('acme', "sales/{$sale->id}"))
            ->assertInertia(fn (Assert $page) => $page->where('sale.customerName', $name)->where('sale.customerPhone', $phone));
    })->with('customers');

    it('is paid in full when no cash amount is given', function () {
        counterStaff(['pharmacy.sales.create'], till: true);

        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 2]],
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
        expect($sale->amount_tendered_minor)->toBe(500)->and($sale->change_minor)->toBe(0);
    });

    it('hands over unpaid, to be paid at the cashier', function () {
        counterSettings(['print_order_slip' => true, 'direct_payment' => 'choose']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view'], till: true);

        $this->post($this->workspaceUrl('acme', 'orders'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 3]],
            'pay_later' => '1',
            'customer_name' => 'Deng',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Order O-000001 handed over: to pay at the cashier.');

        $order = theOrder();
        expect($order->status)->toBe(CounterOrder::HANDED_OVER)
            ->and($order->workflow->value)->toBe('order_dispense_pay')
            ->and(panadolTaken())->toBe(3)
            ->and($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
        $this->get($this->workspaceUrl('acme', "orders/{$order->id}/print/order_slip"))
            ->assertInertia(fn (Assert $page) => $page->where('document.instruction', 'Pay at the cashier with this slip.'));
        $this->get($this->workspaceUrl('acme', 'orders'))->assertInertia(fn (Assert $page) => $page->where('orders.0.awaits', 'payment'));

        payOrder($order)->assertSessionHasNoErrors();
        expect(theOrder()->status)->toBe(CounterOrder::COMPLETED)
            ->and($this->asTenant('acme', fn () => Sale::query()->sole()->customer_name))->toBe('Deng')
            ->and(panadolTaken())->toBe(3);
    });

    it('is paid or unpaid as the branch says', function (string $setting, bool $paidAllowed, bool $unpaidAllowed) {
        counterSettings(['direct_payment' => $setting]);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view'], till: true);
        $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page->where('counter.directPayment', $setting));

        $paid = $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ]);
        $paidAllowed ? $paid->assertSessionHasNoErrors() : $paid->assertSessionHasErrors('items');

        $unpaid = $this->post($this->workspaceUrl('acme', 'orders'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
            'pay_later' => '1',
        ]);

        $unpaidAllowed ? $unpaid->assertSessionHasNoErrors() : $unpaid->assertSessionHasErrors('items');
    })->with([
        'paid at the counter' => ['paid', true, false],
        'unpaid, paid at the cashier' => ['unpaid', false, true],
        'the seller chooses' => ['choose', true, true],
    ]);

    it('is not taken where the branch takes orders first', function () {
        counterSettings(['workflow' => 'order_pay_dispense']);
        counterStaff(['pharmacy.sales.create'], till: true);

        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => '10.00',
        ])->assertSessionHasErrors('items');

        expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
    });

    it('tells the counter how the branch works', function () {
        counterSettings(['workflow' => 'order_dispense_pay', 'require_customer_above_minor' => 50000]);
        counterStaff(['pharmacy.sales.create', 'pharmacy.customers.view']);

        $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page
            ->where('counter.workflow', 'order_dispense_pay')
            ->where('counter.takesOrders', true)
            ->where('counter.requireCustomerAboveMinor', 50000)
            ->where('counter.findCustomers', true));
    });
});

describe('orders in steps', function () {
    it('takes, pays and hands over an order once, at different counters', function (string $workflow, array|Closure $customer, ?string $name, ?string $phone, ?bool $registered) {
        counterSettings(['workflow' => $workflow]);
        $customer = $customer instanceof Closure ? $customer() : $customer;
        $paysFirst = $workflow !== 'order_dispense_pay';

        // The order counter: no till needed to take an order.
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view']);
        takeOrder(4, $customer)->assertSessionHasNoErrors();
        $order = theOrder();
        expect($order->number)->toBe('O-000001')
            ->and($order->status)->toBe(CounterOrder::OPEN)
            ->and($order->total_minor)->toBe(1000)
            ->and($order->customer_name)->toBe($name)
            ->and($order->customer_phone)->toBe($phone)
            ->and(panadolTaken())->toBe(0);

        $cashier = fn () => counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view'], till: true);
        $dispenser = fn () => counterStaff(['pharmacy.sales.handover', 'pharmacy.sales.view']);

        if ($paysFirst) {
            $dispenser();
            handOverOrder($order)->assertSessionHasErrors('order');
            $cashier();
            payOrder($order)->assertSessionHasNoErrors();
            expect(theOrder()->status)->toBe(CounterOrder::PAID)->and(panadolTaken())->toBe(4);
            $dispenser();
            handOverOrder($order)->assertSessionHasNoErrors();
        } else {
            $cashier();
            payOrder($order)->assertSessionHasErrors('payment_method');
            $dispenser();
            handOverOrder($order)->assertSessionHasNoErrors();
            expect(theOrder()->status)->toBe(CounterOrder::HANDED_OVER)->and(panadolTaken())->toBe(4);
            $cashier();
            payOrder($order)->assertSessionHasNoErrors();
        }

        $order = theOrder();
        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
        expect($order->status)->toBe(CounterOrder::COMPLETED)
            ->and($order->sale_id)->toBe($sale->id)
            ->and($sale->total_minor)->toBe(1000)
            ->and($sale->customer_name)->toBe($name)
            ->and($sale->customer_phone)->toBe($phone)
            ->and($sale->customer_id)->toBe($registered ? $this->regular->id : null)
            // Stock left the shelf once, soonest expiry first, and the sale sold that stock.
            ->and(panadolTaken())->toBe(4)
            ->and($this->asTenant('acme', fn () => $sale->lines()->pluck('quantity')->sort()->values()->all()))->toBe([1, 3]);

        $events = array_map(fn ($event) => Envelope::fromArray($event)->eventType, $this->platform()->moduleEvents['finance']);
        expect($events)->toBe(['finance.invoice.issued', 'finance.payment.received']);
    })->with([
        'order → payment → dispensing' => 'order_pay_dispense',
        'order → dispensing → payment' => 'order_dispense_pay',
        'order → payment → collection' => 'order_pay_collect',
    ])->with('customers');

    it('does each step once, however often it is sent', function () {
        counterSettings(['workflow' => 'order_dispense_pay']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.handover', 'pharmacy.sales.view'], till: true);
        $key = (string) Str::uuid();

        takeOrder(2, key: $key);
        takeOrder(2, key: $key);
        expect($this->asTenant('acme', fn () => CounterOrder::query()->count()))->toBe(1);

        $order = theOrder();
        handOverOrder($order)->assertSessionHasNoErrors();
        handOverOrder($order)->assertSessionHasNoErrors();
        payOrder($order)->assertSessionHasNoErrors();
        payOrder($order)->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(1)
            ->and(panadolTaken())->toBe(2)
            ->and(count($this->platform()->moduleEvents['finance']))->toBe(2);
    });

    it('works with one person doing every step', function () {
        counterSettings(['workflow' => 'order_pay_dispense']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.handover', 'pharmacy.sales.view'], till: true);

        takeOrder(1);
        payOrder(theOrder())->assertSessionHasNoErrors();
        handOverOrder(theOrder())->assertSessionHasNoErrors();

        expect(theOrder()->status)->toBe(CounterOrder::COMPLETED);
    });

    it('does not take prescription-only medicines', function () {
        counterSettings(['workflow' => 'order_pay_dispense']);
        counterStaff(['pharmacy.sales.create']);

        $this->post($this->workspaceUrl('acme', 'orders'), [
            'items' => [['item_id' => $this->amox->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('items.0.item_id');
    });

    it('does not take more than the branch has', function () {
        counterSettings(['workflow' => 'order_pay_dispense']);
        counterStaff(['pharmacy.sales.create']);

        takeOrder(24)->assertSessionHasErrors('items.0.quantity');
    });

    it('is not taken where the branch sells directly', function () {
        counterStaff(['pharmacy.sales.create']);

        takeOrder(1)->assertSessionHasErrors('items');
    });

    it('lets the cashier name the customer when the order has none', function () {
        counterSettings(['workflow' => 'order_pay_collect']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view'], till: true);
        takeOrder(1);

        payOrder(theOrder(), ['customer_name' => 'Nyibol', 'customer_phone' => '0920'])->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => Sale::query()->sole()->customer_name))->toBe('Nyibol')
            ->and(theOrder()->customer_phone)->toBe('0920');
    });
});

describe('cancelling', function () {
    it('puts back what was dispensed for an unpaid order', function () {
        counterSettings(['workflow' => 'order_dispense_pay']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.handover']);
        takeOrder(5);
        handOverOrder(theOrder());
        expect(panadolTaken())->toBe(5);

        $this->post($this->workspaceUrl('acme', 'orders/'.theOrder()->id.'/cancel'), ['reason' => 'Changed their mind'])->assertSessionHasNoErrors();

        expect(theOrder()->status)->toBe(CounterOrder::CANCELLED)
            ->and(panadolTaken())->toBe(0)
            ->and($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
    });

    it('cancels a paid order only by voiding its sale', function () {
        counterSettings(['workflow' => 'order_pay_dispense']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view', 'pharmacy.sales.void'], till: true);
        takeOrder(3);
        payOrder(theOrder());

        $this->post($this->workspaceUrl('acme', 'orders/'.theOrder()->id.'/cancel'), ['reason' => 'No'])->assertSessionHasErrors('reason');

        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
        $this->post($this->workspaceUrl('acme', "sales/{$sale->id}/void"), ['reason' => 'Wrong medicine'])->assertSessionHasNoErrors();

        expect(theOrder()->status)->toBe(CounterOrder::CANCELLED)
            ->and(theOrder()->cancel_reason)->toBe('Sale S-000001 voided: Wrong medicine')
            ->and(panadolTaken())->toBe(0);
        // Nothing left to hand over.
        counterStaff(['pharmacy.sales.handover']);
        handOverOrder(theOrder())->assertSessionHasErrors('order');
    });
});

describe('customer rules', function () {
    it('never holds up a walk-in sale by default', function () {
        counterStaff(['pharmacy.sales.create'], till: true);

        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 20]],
            'payment_method' => 'cash',
            'amount_tendered' => '100.00',
        ])->assertSessionHasNoErrors();
    });

    it('asks for the customer where the branch needs it', function (array $settings, int $quantity, bool $needed) {
        counterSettings($settings);
        counterStaff(['pharmacy.sales.create'], till: true);
        $sell = fn (array $customer) => $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => $quantity]],
            'payment_method' => 'cash',
            'amount_tendered' => '100.00',
            ...$customer,
        ]);

        $walkIn = $sell([]);
        $needed ? $walkIn->assertSessionHasErrors('customer_name') : $walkIn->assertSessionHasNoErrors();
        $sell(['customer_name' => 'Deng'])->assertSessionHasNoErrors();
        $sell(['customer_id' => $this->regular->id])->assertSessionHasNoErrors();
    })->with([
        'always' => [['require_customer_always' => true], 1, true],
        'above the amount' => [['require_customer_above_minor' => 1000], 5, true],
        'up to the amount' => [['require_customer_above_minor' => 1000], 4, false],
        'only for prescription medicines' => [['require_customer_for_restricted' => true], 1, false],
    ]);

    it('asks for the patient on a prescription-only sale when the branch needs it', function () {
        counterSettings(['require_customer_for_restricted' => true]);
        $user = counterStaff(['pharmacy.sales.create'], till: true);

        $sell = fn (?string $name) => $this->asTenant('acme', fn () => app(Sell::class)->handle(
            $user, $this->juba, [['item_id' => $this->amox->id, 'quantity' => 1]], PaymentMethod::Card, customerName: $name, authorized: [$this->amox->id],
        ));

        expect(fn () => $sell(null))->toThrow(ValidationException::class);
        expect($sell('Garang')->customer_name)->toBe('Garang');
    });

    it('checks the rules when the order is taken', function () {
        counterSettings(['workflow' => 'order_pay_dispense', 'require_customer_always' => true]);
        counterStaff(['pharmacy.sales.create']);

        takeOrder(1)->assertSessionHasErrors('customer_name');
        takeOrder(1, ['customer_name' => 'Deng'])->assertSessionHasNoErrors();
    });
});

describe('printing', function () {
    it('prints nothing by itself until the branch asks', function () {
        counterStaff(['pharmacy.sales.create'], till: true);

        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => '10.00',
        ])->assertSessionHas('print', []);
    });

    it('prints the receipt of a direct sale once, then marked copies', function () {
        counterSettings(['print_receipt_after_payment' => true, 'print_receipt_after_dispensing' => true, 'paper_size' => '58mm']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view'], till: true);

        $response = $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => '10.00',
        ]);
        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
        $url = $this->workspaceUrl('acme', "sales/{$sale->id}/receipt?auto=1");
        // Payment and dispensing both ask for it: one receipt.
        $response->assertSessionHas('print', [$url]);

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->component('print/document')
            ->where('autoPrint', true)
            ->where('copies', 1)
            ->where('layout.paperSize', '58mm')
            ->where('document.customerName', null));
        // Opened again (a reload, a second screen): it does not print again.
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('autoPrint', false)->where('copies', 1));

        $this->post($this->workspaceUrl('acme', 'printed-documents'), ['document' => 'receipt', 'reference_id' => $sale->id])->assertRedirect();
        $this->get($this->workspaceUrl('acme', "sales/{$sale->id}/receipt"))->assertInertia(fn (Assert $page) => $page->where('copies', 2)->where('autoPrint', false));

        // Printing changed nothing else.
        expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(1)
            ->and(panadolTaken())->toBe(1)
            ->and($this->asTenant('acme', fn () => PrintedDocument::query()->orderBy('copy')->get(['copy', 'automatic'])->toArray()))
            ->toBe([['copy' => 1, 'automatic' => true], ['copy' => 2, 'automatic' => false]]);
    });

    it('prints each document of an order at its step, the receipt once', function () {
        counterSettings([
            'workflow' => 'order_pay_collect',
            'print_order_slip' => true,
            'print_collection_slip' => true,
            'print_receipt_after_payment' => true,
            'print_receipt_after_dispensing' => true,
        ]);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.handover', 'pharmacy.sales.view'], till: true);

        $taken = takeOrder(2);
        $order = theOrder();
        $taken->assertSessionHas('print', [$this->workspaceUrl('acme', "orders/{$order->id}/print/order_slip?auto=1")]);

        $paid = payOrder($order);
        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
        $paid->assertSessionHas('print', [
            $this->workspaceUrl('acme', "sales/{$sale->id}/receipt?auto=1"),
            $this->workspaceUrl('acme', "orders/{$order->id}/print/collection_slip?auto=1"),
        ]);
        $this->get($this->workspaceUrl('acme', "sales/{$sale->id}/receipt?auto=1"))->assertInertia(fn (Assert $page) => $page
            ->where('autoPrint', true)
            ->where('document.orderNumber', 'O-000001')
            ->where('document.barcode', 'O-000001'));

        // The receipt printed after payment: not again after collection.
        handOverOrder($order)->assertSessionHas('print', []);
    });

    it('leaves prices off slips when the branch says so', function () {
        counterSettings(['workflow' => 'order_pay_dispense', 'slip_prices' => false]);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view']);
        takeOrder(2, ['customer_name' => 'Mary']);

        $this->get($this->workspaceUrl('acme', 'orders/'.theOrder()->id.'/print/order_slip'))->assertInertia(fn (Assert $page) => $page
            ->where('document.prices', false)
            ->where('document.instruction', 'Take this slip to the cashier to pay.')
            ->where('document.customerName', 'Mary')
            ->where('autoPrint', false)
            ->where('copies', 0));
    });

    it('gives a collection slip only for a paid order collected', function () {
        counterSettings(['workflow' => 'order_pay_collect']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view'], till: true);
        takeOrder(1);

        $this->get($this->workspaceUrl('acme', 'orders/'.theOrder()->id.'/print/collection_slip'))->assertNotFound();
        payOrder(theOrder());
        $this->get($this->workspaceUrl('acme', 'orders/'.theOrder()->id.'/print/collection_slip'))->assertOk();
    });
});

describe('who sees what', function () {
    it('keeps orders to their branch', function () {
        counterSettings(['workflow' => 'order_pay_dispense']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view']);
        takeOrder(1);
        $order = theOrder();

        $this->actingAsMember('acme', ['pharmacy.sales.create', 'pharmacy.sales.view', 'pharmacy.sales.handover'], branch: $this->wau);
        $this->get($this->workspaceUrl('acme', "orders/{$order->id}"))->assertNotFound();
        $this->post($this->workspaceUrl('acme', "orders/{$order->id}/hand-over"))->assertNotFound();
        $this->get($this->workspaceUrl('acme', 'orders/'.$order->id.'/print/order_slip'))->assertNotFound();
        $this->post($this->workspaceUrl('acme', 'printed-documents'), ['document' => 'order_slip', 'reference_id' => $order->id])->assertNotFound();
    });

    it('lets only those allowed hand over, pay and change the settings', function () {
        counterSettings(['workflow' => 'order_pay_dispense']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view'], till: true);
        takeOrder(1);
        payOrder(theOrder());

        handOverOrder(theOrder())->assertForbidden();
        $this->get($this->workspaceUrl('acme', 'counter-settings'))->assertForbidden();

        counterStaff(['pharmacy.sales.handover']);
        $this->post($this->workspaceUrl('acme', 'orders/'.theOrder()->id.'/pay'), ['payment_method' => 'cash'])->assertForbidden();
    });

    it('finds an order by its scanned number', function () {
        counterSettings(['workflow' => 'order_pay_dispense']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.view']);
        takeOrder(1);

        $this->get($this->workspaceUrl('acme', 'orders?find=o-000001'))->assertRedirect($this->workspaceUrl('acme', 'orders/'.theOrder()->id));
        $this->get($this->workspaceUrl('acme', 'orders?find=O-999'))->assertSessionHasErrors('find');
        $this->get($this->workspaceUrl('acme', 'orders'))->assertInertia(fn (Assert $page) => $page
            ->component('orders/index')
            ->has('orders', 1)
            ->where('orders.0.awaits', 'payment')
            ->where('orders.0.customerName', null));
    });
});

describe('counter settings', function () {
    it('saves the branch\'s workflow, rules and printing', function () {
        counterStaff(['pharmacy.sales.settings']);

        $this->post($this->workspaceUrl('acme', 'counter-settings'), [
            'workflow' => 'order_pay_collect',
            'direct_payment' => 'choose',
            'require_customer_above' => '100.00',
            'print_order_slip' => '1',
            'paper_size' => 'a4',
            'printer_name' => 'Counter 1',
            'receipt_footer' => 'Keep medicines away from children.',
            'show_barcode' => '0',
            'slip_prices' => '1',
        ])->assertSessionHasNoErrors();

        $settings = $this->asTenant('acme', fn () => BranchCounterSettings::forBranch($this->juba, 'pharmacy'));
        expect($settings->workflow->value)->toBe('order_pay_collect')
            ->and($settings->direct_payment)->toBe('choose')
            ->and($settings->require_customer_above_minor)->toBe(10000)
            ->and($settings->print_order_slip)->toBeTrue()
            ->and($settings->print_receipt_after_payment)->toBeFalse()
            ->and($settings->paper_size)->toBe('a4')
            ->and($settings->show_barcode)->toBeFalse()
            ->and($settings->receipt_footer)->toBe('Keep medicines away from children.');

        // Wau keeps its own: a direct sale, nothing printed.
        expect($this->asTenant('acme', fn () => BranchCounterSettings::forBranch($this->wau, 'pharmacy')->workflow->value))->toBe('direct');
    });

    it('finishes open orders before going back to direct sales', function () {
        counterSettings(['workflow' => 'order_pay_dispense']);
        counterStaff(['pharmacy.sales.create', 'pharmacy.sales.settings']);
        takeOrder(1);

        $this->post($this->workspaceUrl('acme', 'counter-settings'), ['workflow' => 'direct', 'paper_size' => '80mm'])->assertSessionHasErrors('workflow');
    });
});

describe('registered customers', function () {
    it('registers a customer and keeps what they bought', function () {
        counterStaff(['pharmacy.sales.create', 'pharmacy.customers.view', 'pharmacy.customers.manage'], till: true);

        $this->post($this->workspaceUrl('acme', 'customers'), ['name' => 'Ayen Mabior', 'phone' => '0955'])->assertSessionHasNoErrors()->assertRedirect();
        $customer = $this->asTenant('acme', fn () => Customer::query()->where('name', 'Ayen Mabior')->sole());

        $this->getJson($this->workspaceUrl('acme', 'customers/lookup?search=0955'))->assertJsonPath('customers.0.id', $customer->id);

        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 2]],
            'payment_method' => 'cash',
            'amount_tendered' => '10.00',
            'customer_id' => $customer->id,
        ])->assertSessionHasNoErrors();

        $this->get($this->workspaceUrl('acme', "customers/{$customer->id}"))->assertInertia(fn (Assert $page) => $page
            ->component('customers/show')
            ->where('totals.purchases', 1)
            ->where('totals.spentMinor', 500)
            ->has('sales', 1)
            ->where('sales.0.items', [$this->para->item->name]));
    });

    it('keeps retail customers out of the pharmacy', function () {
        $shopper = $this->asTenant('acme', fn () => Customer::query()->create(['application' => 'retail', 'number' => 'C-90009', 'name' => 'Shop Customer']));
        counterStaff(['pharmacy.sales.create', 'pharmacy.customers.view'], till: true);

        $this->get($this->workspaceUrl('acme', "customers/{$shopper->id}"))->assertNotFound();
        $this->getJson($this->workspaceUrl('acme', 'customers/lookup?search=Shop'))->assertJsonCount(0, 'customers');
        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => '10.00',
            'customer_id' => $shopper->id,
        ])->assertSessionHasErrors('customer_id');
    });
});
