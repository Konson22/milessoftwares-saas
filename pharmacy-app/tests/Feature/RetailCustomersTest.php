<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Retail\Models\Product;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerPayment;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\TillSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Retail R3 (docs/erp/retail-roadmap.md): account customers. A sale on
| account is invoiced to the customer, due after their payment terms, within
| their credit limit; payments settle the oldest sales first. What a
| customer owes is what Finance holds as their receivable: the same invoice,
| return and payment events.
*/

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->soap = $this->asTenant('acme', fn () => Product::factory()->create(['name' => 'Soap', 'barcode' => '6001234500017', 'sell_price_minor' => 300]));
    $keeper = User::factory()->create();
    $this->asTenant('acme', fn () => app(Stock::class)->add($this->soap, $this->juba, 'LOT-1', null, 100, 200, $keeper));

    $this->kiir = $this->asTenant('acme', fn () => Customer::factory()->create([
        'number' => 'C-90001', 'name' => 'Kiir Traders', 'credit_limit_minor' => 2000, 'payment_terms_days' => 30,
    ]));
});

function creditUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path, 'retail');
}

/**
 * Signs in a shop seller who may sell on account, with their till open.
 */
function creditSeller(array $permissions = ['retail.sales.create', 'retail.sales.view', 'retail.sales.credit', 'retail.customers.view']): User
{
    $user = test()->actingAsMember('acme', $permissions, branch: test()->juba, application: 'retail');
    test()->openTill($user, test()->juba);
    assert($user instanceof User);

    return $user;
}

/**
 * Sells soap on account to the customer, and returns the sale.
 */
function sellOnAccount(int $quantity, ?Customer $customer = null): Sale
{
    test()->post(creditUrl('sales'), [
        'submission_id' => (string) Str::uuid(),
        'items' => [['item_id' => test()->soap->id, 'quantity' => $quantity]],
        'payment_method' => 'account',
        'customer_id' => ($customer ?? test()->kiir)->id,
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Sale::query()->latest('sold_at')->latest('number')->firstOrFail());
}

/**
 * @return list<array<string, mixed>>
 */
function accountEvents(string $type): array
{
    return DB::table('platform_outbox')->where('event_type', $type)->orderBy('created_at')->get()
        ->map(fn ($row) => json_decode($row->payload, true))->all();
}

describe('selling on account', function () {
    it('invoices the customer, due after their terms, with nothing paid yet', function () {
        creditSeller();

        $sale = sellOnAccount(2);

        expect($sale->payment_method->value)->toBe('account')
            ->and($sale->customer_id)->toBe($this->kiir->id)
            ->and($sale->customer_name)->toBe('Kiir Traders')
            ->and($sale->due_on->toDateString())->toBe('2026-11-18')
            ->and($sale->till_session_id)->not->toBeNull();

        $invoice = accountEvents('finance.invoice.issued')[0];
        expect($invoice['total_minor'])->toBe(600)
            ->and($invoice['due_on'])->toBe('2026-11-18')
            ->and($invoice['customer_reference'])->toBe('Kiir Traders (C-90001)')
            ->and(accountEvents('finance.payment.received'))->toBe([]);

        $this->get(creditUrl("sales/{$sale->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('sale.onAccount', true)
            ->where('sale.customer.name', 'Kiir Traders')
            ->where('sale.dueOn', '2026-11-18')
            ->where('sale.owedMinor', 600));
    });

    it('offers the customers with what they can still buy at the counter', function () {
        creditSeller();
        sellOnAccount(2);
        $this->asTenant('acme', fn () => Customer::factory()->create(['name' => 'Cash Only', 'credit_limit_minor' => 0]));
        $this->asTenant('acme', fn () => Customer::factory()->create(['name' => 'Gone', 'active' => false]));

        $this->get(creditUrl('sales/create'))->assertInertia(fn (Assert $page) => $page
            ->has('customers', 1)
            ->where('customers.0.name', 'Kiir Traders')
            ->where('customers.0.availableMinor', 1400)
            ->where('paymentMethods', fn ($methods) => ! collect($methods)->contains('value', 'account')));
    });

    it('refuses a sale over the credit limit', function () {
        creditSeller();
        sellOnAccount(5);

        $this->post(creditUrl('sales'), [
            'items' => [['item_id' => $this->soap->id, 'quantity' => 2]],
            'payment_method' => 'account',
            'customer_id' => $this->kiir->id,
        ])->assertSessionHasErrors(['customer_id' => "Over Kiir Traders's credit limit of SSP 20.00: they owe SSP 15.00 and this sale is SSP 6.00."]);

        // Exactly up to the limit is fine.
        sellOnAccount(1);
        expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(2);
    });

    it('refuses a customer without credit', function () {
        creditSeller();
        $cashOnly = $this->asTenant('acme', fn () => Customer::factory()->create(['name' => 'Cash Only', 'credit_limit_minor' => 0]));

        $this->post(creditUrl('sales'), [
            'items' => [['item_id' => $this->soap->id, 'quantity' => 1]],
            'payment_method' => 'account',
            'customer_id' => $cashOnly->id,
        ])->assertSessionHasErrors(['customer_id' => 'Cash Only may not buy on account.']);
    });

    it('needs the customer, active and of the shop', function () {
        creditSeller();
        $pharmacyCustomer = $this->asTenant('acme', fn () => Customer::factory()->create(['application' => 'pharmacy', 'credit_limit_minor' => 100_000]));
        $inactive = $this->asTenant('acme', fn () => Customer::factory()->create(['active' => false]));

        foreach ([null, $pharmacyCustomer->id, $inactive->id] as $customerId) {
            $this->post(creditUrl('sales'), array_filter([
                'items' => [['item_id' => $this->soap->id, 'quantity' => 1]],
                'payment_method' => 'account',
                'customer_id' => $customerId,
            ]))->assertSessionHasErrors('customer_id');
        }

        expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
    });

    it('lets only members with credit sell on account', function () {
        creditSeller(['retail.sales.create', 'retail.sales.view']);

        $this->get(creditUrl('sales/create'))->assertInertia(fn (Assert $page) => $page->where('customers', []));
        $this->post(creditUrl('sales'), [
            'items' => [['item_id' => $this->soap->id, 'quantity' => 1]],
            'payment_method' => 'account',
            'customer_id' => $this->kiir->id,
        ])->assertSessionHasErrors(['payment_method' => 'You may not sell on account.']);

        expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
    });

    it('records the customer on a sale paid at once', function () {
        creditSeller();

        $this->post(creditUrl('sales'), [
            'items' => [['item_id' => $this->soap->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => '5.00',
            'customer_id' => $this->kiir->id,
        ])->assertSessionHasNoErrors();

        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
        expect($sale->customer_id)->toBe($this->kiir->id)
            ->and($sale->due_on)->toBeNull()
            ->and(accountEvents('finance.payment.received'))->toHaveCount(1);

        // Paid at once: nothing owed.
        $this->get(creditUrl("customers/{$this->kiir->id}"))->assertInertia(fn (Assert $page) => $page->where('owedMinor', 0)->has('sales', 0));
    });
});

describe('payments', function () {
    it('settles the oldest sales first, each part a payment of its invoice', function () {
        creditSeller();
        $first = sellOnAccount(2);
        $this->travel(1)->days();
        $second = sellOnAccount(3);

        $this->post(creditUrl("customers/{$this->kiir->id}/payments"), [
            'amount' => '10.00', 'method' => 'mobile_money', 'reference' => 'MM-55', 'submission_id' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $payment = $this->asTenant('acme', fn () => CustomerPayment::query()->with('allocations')->sole());
        expect($payment->amount_minor)->toBe(1000)
            ->and($payment->allocations->mapWithKeys(fn ($allocation) => [$allocation->sale_id => $allocation->amount_minor])->all())
            ->toBe([$first->id => 600, $second->id => 400]);

        expect(collect(accountEvents('finance.payment.received'))->map(fn ($event) => [$event['payment_reference'], $event['invoice_number'], $event['amount_minor'], $event['method'], $event['processor_reference']])->all())->toBe([
            ["{$payment->number}/{$first->number}", $first->number, 600, 'mobile_money', 'MM-55'],
            ["{$payment->number}/{$second->number}", $second->number, 400, 'mobile_money', 'MM-55'],
        ]);

        $this->get(creditUrl("customers/{$this->kiir->id}"))->assertInertia(fn (Assert $page) => $page
            ->component('retail/customers/show')
            ->where('owedMinor', 500)
            ->where('availableMinor', 1500)
            ->where('sales.0.owedMinor', 0)
            ->where('sales.1.paidMinor', 400)
            ->where('sales.1.owedMinor', 500)
            ->where('payments.0.settled', [$first->number, $second->number]));
    });

    it('refuses more than is owed', function () {
        creditSeller();
        sellOnAccount(2);

        $this->post(creditUrl("customers/{$this->kiir->id}/payments"), ['amount' => '6.01', 'method' => 'cash'])
            ->assertSessionHasErrors(['amount' => 'Kiir Traders owes only SSP 6.00.']);
        $this->post(creditUrl("customers/{$this->kiir->id}/payments"), ['amount' => '1.00', 'method' => 'account'])
            ->assertSessionHasErrors('method');

        expect($this->asTenant('acme', fn () => CustomerPayment::query()->count()))->toBe(0);
    });

    it('records a payment once however often it is sent', function () {
        creditSeller();
        sellOnAccount(2);
        $submission = (string) Str::uuid();

        foreach ([1, 2] as $attempt) {
            $this->post(creditUrl("customers/{$this->kiir->id}/payments"), ['amount' => '2.00', 'method' => 'cash', 'submission_id' => $submission])
                ->assertSessionHasNoErrors();
        }

        expect($this->asTenant('acme', fn () => CustomerPayment::query()->count()))->toBe(1)
            ->and(accountEvents('finance.payment.received'))->toHaveCount(1);
    });

    it('puts cash payments into the till it is counted in', function () {
        $seller = creditSeller();
        sellOnAccount(5);

        $this->post(creditUrl("customers/{$this->kiir->id}/payments"), ['amount' => '4.00', 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->post(creditUrl("customers/{$this->kiir->id}/payments"), ['amount' => '3.00', 'method' => 'card'])->assertSessionHasNoErrors();

        $summary = $this->asTenant('acme', fn () => app(Tills::class)->summary(TillSession::query()->where('opened_by', $seller->id)->sole()));
        expect($summary['collectedMinor'])->toBe(['card' => 300, 'cash' => 400])
            ->and($summary['collectionsCount'])->toBe(2)
            ->and($summary['takenMinor'])->toBe(['account' => 1500])
            ->and($summary['expectedCashMinor'])->toBe(400);
    });

    it('takes cash only into an open till', function () {
        creditSeller();
        sellOnAccount(2);
        // Someone else at the branch, without a till of their own.
        $this->actingAsMember('acme', ['retail.sales.create', 'retail.customers.view'], branch: $this->juba, application: 'retail');

        $this->post(creditUrl("customers/{$this->kiir->id}/payments"), ['amount' => '1.00', 'method' => 'cash'])->assertSessionHasErrors('amount');
        expect($this->asTenant('acme', fn () => CustomerPayment::query()->count()))->toBe(0);
    });
});

describe('voids and returns on account', function () {
    it('voids an unpaid sale without a refund, but not one the customer has paid towards', function () {
        creditSeller(['retail.sales.create', 'retail.sales.view', 'retail.sales.void', 'retail.sales.credit', 'retail.customers.view']);
        $unpaid = sellOnAccount(1);
        $paid = sellOnAccount(2);

        $this->post(creditUrl("sales/{$unpaid->id}/void"), ['reason' => 'Wrong customer'])->assertSessionHasNoErrors();
        expect(accountEvents('finance.invoice.voided')[0]['refund'])->toBeNull();

        $this->post(creditUrl("customers/{$this->kiir->id}/payments"), ['amount' => '1.00', 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->get(creditUrl("sales/{$paid->id}"))->assertInertia(fn (Assert $page) => $page->where('canVoid', false));
        $this->post(creditUrl("sales/{$paid->id}/void"), ['reason' => 'Changed mind'])->assertSessionHasErrors();

        expect($paid->refresh()->status)->toBe(Sale::COMPLETED);
    });

    it('takes a return off what is still owed, never more, without cash back', function () {
        creditSeller(['retail.sales.create', 'retail.sales.view', 'retail.sales.void', 'retail.sales.credit', 'retail.customers.view']);
        $sale = sellOnAccount(3);
        $this->post(creditUrl("customers/{$this->kiir->id}/payments"), ['amount' => '6.00', 'method' => 'cash'])->assertSessionHasNoErrors();
        $line = $this->asTenant('acme', fn () => $sale->lines()->sole());

        $this->post(creditUrl("sales/{$sale->id}/returns"), [
            'reason' => 'Damaged', 'lines' => [['sale_line_id' => $line->id, 'quantity' => 2, 'restock' => true]],
        ])->assertSessionHasErrors();

        $this->post(creditUrl("sales/{$sale->id}/returns"), [
            'reason' => 'Damaged', 'lines' => [['sale_line_id' => $line->id, 'quantity' => 1, 'restock' => true]],
        ])->assertSessionHasNoErrors();

        $return = accountEvents('finance.sales_return.issued');
        expect($return)->toHaveCount(1)
            ->and($return[0]['total_minor'])->toBe(300)
            ->and($return[0]['refund'])->toBeNull();

        $this->get(creditUrl("customers/{$this->kiir->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('owedMinor', 0)
            ->where('sales.0.returnedMinor', 300)
            ->where('canTakePayment', false));
    });
});

describe('the statement', function () {
    it('owes what Finance holds: invoices less returns and payments', function () {
        creditSeller(['retail.sales.create', 'retail.sales.view', 'retail.sales.void', 'retail.sales.credit', 'retail.customers.view']);
        $first = sellOnAccount(4);
        sellOnAccount(2);
        $this->post(creditUrl("customers/{$this->kiir->id}/payments"), ['amount' => '7.00', 'method' => 'card'])->assertSessionHasNoErrors();
        $line = $this->asTenant('acme', fn () => $first->lines()->sole());
        $this->post(creditUrl("sales/{$first->id}/returns"), [
            'reason' => 'Short dated', 'lines' => [['sale_line_id' => $line->id, 'quantity' => 1, 'restock' => false]],
        ])->assertSessionHasNoErrors();

        $receivable = collect(accountEvents('finance.invoice.issued'))->sum('total_minor')
            - collect(accountEvents('finance.sales_return.issued'))->sum('total_minor')
            - collect(accountEvents('finance.payment.received'))->sum('amount_minor');

        $this->get(creditUrl("customers/{$this->kiir->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('owedMinor', $receivable)
            ->where('owedMinor', 1800 - 300 - 700));
        $this->get(creditUrl('customers'))->assertInertia(fn (Assert $page) => $page
            ->where('customers.0.owedMinor', 800)
            ->where('customers.0.overdue', false));
    });

    it('shows what is overdue', function () {
        creditSeller();
        sellOnAccount(2);
        $this->travel(31)->days();
        sellOnAccount(1);

        $this->get(creditUrl("customers/{$this->kiir->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('owedMinor', 900)
            ->where('overdueMinor', 600)
            ->where('sales.0.overdue', true)
            ->where('sales.1.overdue', false));
        $this->get(creditUrl('customers'))->assertInertia(fn (Assert $page) => $page->where('customers.0.overdue', true));
    });
});

describe('managing customers', function () {
    it('adds and edits a customer with a number of their own', function () {
        $this->actingAsMember('acme', ['retail.customers.view', 'retail.customers.manage'], branch: $this->juba, application: 'retail');

        $this->post(creditUrl('customers'), [
            'name' => ' Juba Hotel ', 'phone' => '0912', 'credit_limit' => '500.00', 'payment_terms_days' => 14, 'active' => '1',
        ])->assertSessionHasNoErrors();

        $hotel = $this->asTenant('acme', fn () => Customer::query()->where('name', 'Juba Hotel')->sole());
        expect($hotel->application)->toBe('retail')
            ->and($hotel->number)->toStartWith('C')
            ->and($hotel->credit_limit_minor)->toBe(50_000)
            ->and($hotel->payment_terms_days)->toBe(14);

        $this->post(creditUrl('customers'), ['name' => 'Juba Hotel', 'credit_limit' => '0', 'payment_terms_days' => 0])
            ->assertSessionHasErrors('name');

        $this->put(creditUrl("customers/{$hotel->id}"), [
            'name' => 'Juba Hotel', 'credit_limit' => '0', 'payment_terms_days' => 0, 'active' => '0',
        ])->assertSessionHasNoErrors();
        expect($hotel->refresh()->active)->toBeFalse();
    });

    it('lets cashiers look customers up, but not change them', function () {
        $this->actingAsMember('acme', ['retail.customers.view'], branch: $this->juba, application: 'retail');

        $this->get(creditUrl('customers'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/customers/index')
            ->has('customers', 1)
            ->where('canManage', false));
        $this->get(creditUrl('customers/create'))->assertForbidden();
        $this->put(creditUrl("customers/{$this->kiir->id}"), ['name' => 'Taken', 'credit_limit' => '99999', 'payment_terms_days' => 1])->assertForbidden();
        $this->post(creditUrl("customers/{$this->kiir->id}/payments"), ['amount' => '1.00', 'method' => 'cash'])->assertForbidden();
    });

    it('keeps the shop customers out of the pharmacy, and the pharmacy ones out of the shop', function () {
        $pharmacyCustomer = $this->asTenant('acme', fn () => Customer::factory()->create(['application' => 'pharmacy']));

        $this->actingAsMember('acme', ['retail.customers.view', 'retail.customers.manage'], branch: $this->juba, application: 'retail');
        $this->get(creditUrl('customers'))->assertInertia(fn (Assert $page) => $page->has('customers', 1));
        $this->get(creditUrl("customers/{$pharmacyCustomer->id}"))->assertNotFound();
        $this->get(creditUrl("customers/{$pharmacyCustomer->id}/edit"))->assertNotFound();

        $this->actingAsMember('acme', ['pharmacy.sales.create', 'pharmacy.sales.view'], branch: $this->juba);
        $this->get(creditUrl('customers'))->assertForbidden();
    });

    it('gives the pharmacy counter no account sales', function () {
        $user = $this->actingAsMember('acme', ['pharmacy.sales.create', 'pharmacy.sales.view'], branch: $this->juba);
        $this->openTill($user, $this->juba);

        $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page->where('customers', []));
        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->soap->id, 'quantity' => 1]],
            'payment_method' => 'account',
            'customer_id' => $this->kiir->id,
        ])->assertSessionHasErrors('payment_method');
    });
});
