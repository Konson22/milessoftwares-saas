<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Supplier;
use App\Models\SupplierCredit;

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
});

/**
 * An account's balance in its normal direction.
 */
function balanceOf(string $systemKey): int
{
    return test()->asTenant('acme', function () use ($systemKey) {
        $account = Account::system($systemKey);
        $lines = $account->lines()->get();

        return $account->type->balance((int) $lines->sum('debit_minor'), (int) $lines->sum('credit_minor'));
    });
}

function sale(string $number = 'S-000001', int $total = 1250, string $method = 'cash'): void
{
    test()->sendEvent('finance.invoice.issued', "sale:{$number}:invoice", [
        'invoice_number' => $number,
        'branch_id' => null,
        'customer_reference' => 'Walk-in',
        'currency' => 'SSP',
        'total_minor' => $total,
        'tax_minor' => 0,
        'due_on' => null,
        'lines' => [['description' => 'Paracetamol 500 mg', 'quantity' => 5, 'amount_minor' => $total, 'revenue_account' => null]],
    ])->assertCreated();

    test()->sendEvent('finance.payment.received', "sale:{$number}:payment", [
        'payment_reference' => $number,
        'invoice_number' => $number,
        'branch_id' => null,
        'currency' => 'SSP',
        'amount_minor' => $total,
        'method' => $method,
        'processor_reference' => null,
    ])->assertCreated();
}

it('posts a paid sale: revenue, money in the till, nothing left owing', function () {
    sale(method: 'mobile_money');

    expect(balanceOf(Account::SALES))->toBe(1250)
        ->and(balanceOf(Account::MOBILE_MONEY))->toBe(1250)
        ->and(balanceOf(Account::RECEIVABLE))->toBe(0);

    $this->asTenant('acme', function () {
        $invoice = Invoice::query()->sole();
        expect($invoice->status())->toBe('paid')
            ->and($invoice->customer_name)->toBe('Walk-in')
            ->and(Journal::query()->pluck('source_type')->all())->toBe(['finance.invoice.issued', 'finance.payment.received']);
    });
});

it('applies a retried event once, and refuses a different event under the same transaction id', function () {
    sale();

    $payload = [
        'payment_reference' => 'S-000001', 'invoice_number' => 'S-000001', 'currency' => 'SSP',
        'amount_minor' => 1250, 'method' => 'cash',
    ];
    $this->sendEvent('finance.payment.received', 'sale:S-000001:payment', $payload + ['branch_id' => null, 'processor_reference' => null])
        ->assertOk()
        ->assertJsonPath('status', 'duplicate');
    $this->sendEvent('finance.payment.received', 'sale:S-000001:payment', ['amount_minor' => 999] + $payload)
        ->assertConflict();

    expect(balanceOf(Account::CASH))->toBe(1250);
    $this->asTenant('acme', fn () => expect(CustomerPayment::query()->count())->toBe(1));
});

it('settles an invoice whose payment arrived first', function () {
    $this->sendEvent('finance.payment.received', 'sale:S-9:payment', [
        'payment_reference' => 'S-9', 'invoice_number' => 'S-9', 'currency' => 'SSP', 'amount_minor' => 500, 'method' => 'card',
    ])->assertCreated();
    $this->sendEvent('finance.invoice.issued', 'sale:S-9:invoice', [
        'invoice_number' => 'S-9', 'currency' => 'SSP', 'total_minor' => 500,
        'lines' => [['description' => 'Cough syrup', 'quantity' => 1, 'amount_minor' => 500]],
    ])->assertCreated();

    expect(balanceOf(Account::RECEIVABLE))->toBe(0);
    $this->asTenant('acme', fn () => expect(Invoice::query()->sole()->status())->toBe('paid'));
});

it('reverses a voided sale and its refund', function () {
    sale();

    $this->sendEvent('finance.invoice.voided', 'sale:S-000001:void', [
        'invoice_number' => 'S-000001', 'currency' => 'SSP', 'total_minor' => 1250, 'reason' => 'Wrong medicine',
        'refund' => ['method' => 'cash', 'amount_minor' => 1250, 'payment_reference' => 'S-000001'],
    ])->assertCreated();

    expect(balanceOf(Account::SALES))->toBe(0)
        ->and(balanceOf(Account::CASH))->toBe(0)
        ->and(balanceOf(Account::RECEIVABLE))->toBe(0);

    $this->asTenant('acme', function () {
        $invoice = Invoice::query()->sole();
        expect($invoice->status())->toBe('void')
            ->and($invoice->void_reason)->toBe('Wrong medicine')
            ->and(Journal::query()->whereKey($invoice->journal_id)->value('reversed_by_id'))->toBe($invoice->void_journal_id);
    });
});

it('asks the product to retry a void that arrives before its invoice', function () {
    $void = fn () => $this->sendEvent('finance.invoice.voided', 'sale:S-7:void', [
        'invoice_number' => 'S-7', 'currency' => 'SSP', 'total_minor' => 300, 'reason' => 'Mistake', 'refund' => null,
    ]);

    $void()->assertStatus(503)->assertJsonPath('error', 'retry_later');
    $this->asTenant('acme', fn () => expect(Journal::query()->count())->toBe(0));

    $this->sendEvent('finance.invoice.issued', 'sale:S-7:invoice', [
        'invoice_number' => 'S-7', 'currency' => 'SSP', 'total_minor' => 300, 'lines' => [],
    ])->assertCreated();
    $void()->assertCreated();

    expect(balanceOf(Account::RECEIVABLE))->toBe(0);
});

it('takes a partial return off its invoice: revenue, cost back to stock, and the refund', function () {
    $this->sendEvent('finance.invoice.issued', 'sale:S-1:invoice', [
        'invoice_number' => 'S-1', 'currency' => 'SSP', 'total_minor' => 1250,
        'lines' => [['description' => 'Paracetamol', 'quantity' => 5, 'amount_minor' => 1250, 'revenue_account' => null, 'cost_minor' => 500]],
    ])->assertCreated();
    $this->sendEvent('finance.payment.received', 'sale:S-1:payment', [
        'payment_reference' => 'S-1', 'invoice_number' => 'S-1', 'currency' => 'SSP', 'amount_minor' => 1250, 'method' => 'cash',
    ])->assertCreated();

    $this->sendEvent('finance.sales_return.issued', 'sale_return:1', [
        'return_number' => 'R-000001', 'invoice_number' => 'S-1', 'branch_id' => null, 'currency' => 'SSP',
        'total_minor' => 500, 'tax_minor' => 0, 'reason' => 'Wrong strength',
        'lines' => [['description' => 'Paracetamol', 'quantity' => 2, 'amount_minor' => 500, 'cost_minor' => 200]],
        'refund' => ['method' => 'cash', 'amount_minor' => 500, 'payment_reference' => 'R-000001'],
    ])->assertCreated();

    expect(balanceOf(Account::SALES))->toBe(750)
        ->and(balanceOf(Account::CASH))->toBe(750)
        ->and(balanceOf(Account::RECEIVABLE))->toBe(0)
        ->and(balanceOf(Account::PURCHASES))->toBe(300)
        ->and(balanceOf(Account::INVENTORY))->toBe(-300);

    $this->asTenant('acme', function () {
        $invoice = Invoice::query()->sole();
        expect($invoice->credited_minor)->toBe(500)
            ->and($invoice->paid_minor)->toBe(750)
            ->and($invoice->outstanding())->toBe(0)
            ->and($invoice->status())->toBe('paid')
            ->and(CustomerPayment::query()->where('kind', CustomerPayment::REFUND)->value('number'))->toBe('R-000001');
    });
});

it('leaves goods not resold in cost of goods sold, and waits for the invoice', function () {
    $return = fn () => $this->sendEvent('finance.sales_return.issued', 'sale_return:2', [
        'return_number' => 'R-000002', 'invoice_number' => 'S-2', 'currency' => 'SSP',
        'total_minor' => 300, 'reason' => 'Damaged',
        'lines' => [['description' => 'Syrup', 'quantity' => 1, 'amount_minor' => 300, 'cost_minor' => 0]],
        'refund' => null,
    ]);

    $return()->assertStatus(503)->assertJsonPath('error', 'retry_later');

    $this->sendEvent('finance.invoice.issued', 'sale:S-2:invoice', [
        'invoice_number' => 'S-2', 'currency' => 'SSP', 'total_minor' => 900,
        'lines' => [['description' => 'Syrup', 'quantity' => 3, 'amount_minor' => 900, 'cost_minor' => 450]],
    ])->assertCreated();
    $return()->assertCreated();

    expect(balanceOf(Account::SALES))->toBe(600)
        ->and(balanceOf(Account::PURCHASES))->toBe(450)
        ->and(balanceOf(Account::RECEIVABLE))->toBe(600);
    $this->asTenant('acme', fn () => expect(Invoice::query()->sole()->outstanding())->toBe(600));
});

it('posts a supplier bill to inventory and payables, one supplier per product supplier', function () {
    $bill = fn (string $number, int $total) => $this->sendEvent('finance.bill.received', "goods_receipt:{$number}", [
        'bill_number' => $number, 'supplier_reference' => 'INV-77', 'supplier_name' => 'Juba Medical Supplies',
        'supplier_id' => '01a1a1a1-0000-7000-8000-000000000001', 'branch_id' => null, 'currency' => 'SSP',
        'total_minor' => $total, 'tax_minor' => 0, 'received_on' => '2026-10-12', 'due_on' => '2026-11-11',
        'lines' => [['description' => 'Amoxicillin 250 mg (batch A1)', 'quantity' => 100, 'amount_minor' => $total, 'expense_account' => null]],
    ]);

    $bill('GRN-00001', 40000)->assertCreated();
    $bill('GRN-00002', 10000)->assertCreated();

    expect(balanceOf(Account::INVENTORY))->toBe(50000)
        ->and(balanceOf(Account::PAYABLE))->toBe(50000);

    $this->asTenant('acme', function () {
        expect(Supplier::query()->sole()->name)->toBe('Juba Medical Supplies')
            ->and(Bill::query()->orderBy('number')->first()?->due_on?->toDateString())->toBe('2026-11-11')
            ->and(Bill::query()->sum('total_minor'))->toEqual(50000);
    });
});

it('moves sold goods from inventory to cost of goods sold, and back when the sale is voided', function () {
    $this->sendEvent('finance.bill.received', 'goods_receipt:1', [
        'bill_number' => 'GRN-1', 'supplier_name' => 'Juba Medical Supplies', 'currency' => 'SSP',
        'total_minor' => 4000, 'received_on' => '2026-10-01',
        'lines' => [['description' => 'Paracetamol x40', 'quantity' => 40, 'amount_minor' => 4000]],
    ])->assertCreated();

    $this->sendEvent('finance.invoice.issued', 'sale:S-1:invoice', [
        'invoice_number' => 'S-1', 'currency' => 'SSP', 'total_minor' => 1250,
        'lines' => [['description' => 'Paracetamol', 'quantity' => 5, 'amount_minor' => 1250, 'revenue_account' => null, 'cost_minor' => 500]],
    ])->assertCreated();

    expect(balanceOf(Account::INVENTORY))->toBe(3500)
        ->and(balanceOf(Account::PURCHASES))->toBe(500)
        ->and(balanceOf(Account::SALES))->toBe(1250);

    $this->sendEvent('finance.invoice.voided', 'sale:S-1:void', [
        'invoice_number' => 'S-1', 'currency' => 'SSP', 'total_minor' => 1250, 'reason' => 'Returned', 'refund' => null,
    ])->assertCreated();

    expect(balanceOf(Account::INVENTORY))->toBe(4000)
        ->and(balanceOf(Account::PURCHASES))->toBe(0);
});

it('takes returned goods off the bill they came on', function () {
    $credit = fn () => $this->sendEvent('finance.credit_note.received', 'supplier_return:1', [
        'credit_note_number' => 'RTN-00001', 'bill_number' => 'GRN-7', 'supplier_name' => 'Juba Medical Supplies',
        'supplier_id' => '01a1a1a1-0000-7000-8000-000000000001', 'currency' => 'SSP', 'total_minor' => 600,
        'credited_on' => '2026-10-12', 'reason' => 'Damaged in transit',
        'lines' => [['description' => 'Amoxicillin (batch A1)', 'quantity' => 5, 'amount_minor' => 600, 'expense_account' => null]],
    ]);

    // Before its bill: try again later.
    $credit()->assertStatus(503);

    $this->sendEvent('finance.bill.received', 'goods_receipt:7', [
        'bill_number' => 'GRN-7', 'supplier_name' => 'Juba Medical Supplies', 'supplier_id' => '01a1a1a1-0000-7000-8000-000000000001',
        'currency' => 'SSP', 'total_minor' => 4800, 'received_on' => '2026-10-10',
        'lines' => [['description' => 'Amoxicillin (batch A1)', 'quantity' => 40, 'amount_minor' => 4800]],
    ])->assertCreated();
    $credit()->assertCreated();

    expect(balanceOf(Account::PAYABLE))->toBe(4200)
        ->and(balanceOf(Account::INVENTORY))->toBe(4200);

    $this->asTenant('acme', function () {
        $bill = Bill::query()->sole();
        expect($bill->outstanding())->toBe(4200)
            ->and(SupplierCredit::query()->sole())->applied_minor->toBe(600)->bill_id->toBe($bill->id)
            ->and(Supplier::query()->count())->toBe(1);
    });
});

it('posts invoice lines to the revenue account they name', function () {
    $other = $this->asTenant('acme', fn () => Account::query()->where('code', '4900')->sole());

    $this->sendEvent('finance.invoice.issued', 'inv:1', [
        'invoice_number' => 'I-1', 'currency' => 'SSP', 'total_minor' => 1100, 'tax_minor' => 100,
        'lines' => [
            ['description' => 'Goods', 'quantity' => 1, 'amount_minor' => 600, 'revenue_account' => null],
            ['description' => 'Consultation', 'quantity' => 1, 'amount_minor' => 400, 'revenue_account' => '4900'],
        ],
    ])->assertCreated();

    expect(balanceOf(Account::SALES))->toBe(600)
        ->and(balanceOf(Account::TAX))->toBe(100)
        ->and(balanceOf(Account::RECEIVABLE))->toBe(1100)
        ->and($this->asTenant('acme', fn () => $other->type))->toBe(AccountType::Revenue);
});

it('refuses events without a valid token for this organization, product and module', function () {
    $payload = ['payment_reference' => 'P-1', 'currency' => 'SSP', 'amount_minor' => 100, 'method' => 'cash'];
    $this->platform()->organization('globex');

    $this->postJson('http://product.internal/finance/events', [])->assertUnauthorized();
    $this->sendEvent('finance.payment.received', 'p:1', $payload, token: 'not-a-token')->assertUnauthorized();
    $this->sendEvent('finance.payment.received', 'p:1', $payload, token: $this->eventToken('acme', ['audience' => 'hr']))->assertUnauthorized();
    $this->sendEvent('finance.payment.received', 'p:1', $payload, token: $this->eventToken('acme', ['expires' => '-1 minute']))->assertUnauthorized();
    $this->sendEvent('finance.payment.received', 'p:1', $payload, token: $this->eventToken('globex'))->assertForbidden();
    $this->sendEvent('finance.payment.received', 'p:1', $payload, token: $this->eventToken('acme', ['source' => 'retail']))->assertForbidden();

    $this->asTenant('acme', fn () => expect(Journal::query()->count())->toBe(0));
});

it('asks to retry until Finance is set up for the organization', function () {
    $this->platform()->organization('globex');

    $this->sendEvent('finance.payment.received', 'p:1', [
        'payment_reference' => 'P-1', 'currency' => 'SSP', 'amount_minor' => 100, 'method' => 'cash',
    ], slug: 'globex')->assertStatus(503);
});

it('refuses amounts in another currency than the books', function () {
    $this->sendEvent('finance.payment.received', 'p:1', [
        'payment_reference' => 'P-1', 'currency' => 'USD', 'amount_minor' => 100, 'method' => 'cash',
    ])->assertUnprocessable()->assertJsonPath('error', 'currency_mismatch');
});

it('refuses events addressed to another module', function () {
    $this->sendEvent('hr.employee.joined', 'e:1', ['employee_reference' => 'E-1'])
        ->assertUnprocessable();
});
