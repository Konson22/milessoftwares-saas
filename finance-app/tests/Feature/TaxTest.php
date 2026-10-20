<?php

use App\Models\Account;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use Inertia\Testing\AssertableInertia as Assert;

const TAX_OFFICER = [
    'finance.accounts.view', 'finance.journals.view', 'finance.journals.post',
    'finance.receivables.view', 'finance.receivables.manage', 'finance.payables.view', 'finance.payables.manage',
    'finance.banking.view', 'finance.reports.view', 'finance.tax.manage',
];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
    $this->actingAsMember('acme', TAX_OFFICER);
});

function taxUrl(string $path): string
{
    return test()->workspaceUrl('acme', $path);
}

function taxAccountId(string $code): string
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole()->id);
}

function taxCode(string $code, string $rate, bool $recoverable = true): TaxCode
{
    test()->post(taxUrl('tax-codes'), [
        'code' => $code, 'name' => "{$code} rate", 'rate' => $rate,
        'sales' => '1', 'purchases' => '1', 'recoverable' => $recoverable ? '1' : '0',
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => TaxCode::query()->where('code', $code)->sole());
}

/** The tax account's balance, credit side positive (owed to the authority). */
function taxOwed(): int
{
    return test()->asTenant('acme', function () {
        $lines = JournalLine::query()->where('account_id', Account::system(Account::TAX)->id);

        return (int) $lines->sum('credit_minor') - (int) (clone $lines)->sum('debit_minor');
    });
}

function taxEntry(string $date, string $debit, string $credit, string $amount, string $memo = 'Entry')
{
    return test()->post(taxUrl('journals'), [
        'entry_date' => $date,
        'memo' => $memo,
        'lines' => [
            ['account_id' => taxAccountId($debit), 'debit' => $amount],
            ['account_id' => taxAccountId($credit), 'credit' => $amount],
        ],
    ]);
}

function saleWithTax(string $number, string $date, int $net, int $tax): void
{
    test()->sendEvent('finance.invoice.issued', "sale:{$number}:invoice", [
        'invoice_number' => $number, 'branch_id' => null, 'customer_reference' => 'Akol Stores', 'currency' => 'SSP',
        'total_minor' => $net + $tax, 'tax_minor' => $tax, 'due_on' => null,
        'lines' => [['description' => 'Goods', 'quantity' => 1, 'amount_minor' => $net, 'revenue_account' => null]],
    ], occurredAt: "{$date}T09:00:00+00:00")->assertCreated();
}

it('keeps tax codes unique and their rate fixed once added', function () {
    $vat = taxCode('VAT18', '18');
    expect($vat->rate_bp)->toBe(1800)->and($vat->label())->toBe('VAT18 (18%)');

    $this->post(taxUrl('tax-codes'), ['code' => 'vat18', 'name' => 'Again', 'rate' => '5'])->assertSessionHasErrors('code');

    $this->put(taxUrl("tax-codes/{$vat->id}"), ['code' => 'VAT18', 'name' => 'Standard rate', 'rate' => '20', 'sales' => '1', 'purchases' => '0', 'active' => '0'])
        ->assertSessionHasNoErrors();
    $vat = $this->asTenant('acme', fn () => $vat->refresh());
    expect($vat->rate_bp)->toBe(1800)->and($vat->name)->toBe('Standard rate')->and($vat->purchases)->toBeFalse()->and($vat->active)->toBeFalse();
});

it('charges tax per line on invoices, one tagged tax line per code', function () {
    $vat = taxCode('VAT18', '18');
    $zero = taxCode('ZERO', '0');

    $this->post(taxUrl('invoices'), [
        'customer_name' => 'Deng Ltd', 'issued_on' => '2026-10-02',
        'lines' => [
            ['account_id' => taxAccountId('4900'), 'description' => 'Consulting', 'amount' => '100.00', 'tax_code_id' => $vat->id],
            ['account_id' => taxAccountId('4900'), 'description' => 'Training', 'amount' => '0.55', 'tax_code_id' => $vat->id],
            ['account_id' => taxAccountId('4900'), 'description' => 'Exports', 'amount' => '50.00', 'tax_code_id' => $zero->id],
        ],
    ])->assertSessionHasNoErrors();

    $this->asTenant('acme', function () use ($vat) {
        $invoice = Invoice::query()->sole();
        // 18.00 + 0.10 (0.099 rounded) on 100.55; nothing on the zero rate.
        expect($invoice->tax_minor)->toBe(1810)
            ->and($invoice->total_minor)->toBe(15055 + 1810)
            ->and($invoice->lines[0]['tax_code'])->toBe('VAT18')
            ->and($invoice->lines[0]['tax_minor'])->toBe(1800);

        $taxLine = JournalLine::query()->where('account_id', Account::system(Account::TAX)->id)->sole();
        expect($taxLine->credit_minor)->toBe(1810)
            ->and($taxLine->tax_kind)->toBe(JournalLine::OUTPUT)
            ->and($taxLine->tax_code_id)->toBe($vat->id)
            ->and($taxLine->tax_base_minor)->toBe(10055);
    });
});

it('claims recoverable purchase tax and adds the rest to the cost', function () {
    $vat = taxCode('VAT18', '18');
    $excise = taxCode('EXC10', '10', recoverable: false);
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Power company']));

    $this->post(taxUrl('bills'), [
        'supplier_id' => $supplier->id, 'bill_date' => '2026-10-01',
        'lines' => [
            ['account_id' => taxAccountId('6200'), 'description' => 'Electricity', 'amount' => '200', 'tax_code_id' => $vat->id],
            ['account_id' => taxAccountId('6900'), 'description' => 'Fuel', 'amount' => '100', 'tax_code_id' => $excise->id],
        ],
    ])->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $bill = Bill::query()->sole();
        expect($bill->total_minor)->toBe(30000 + 3600 + 1000)->and($bill->tax_minor)->toBe(4600);

        $lines = Journal::query()->where('source_type', 'bill')->sole()->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);
        expect($lines['6200']->debit_minor)->toBe(20000)
            ->and($lines['6900']->debit_minor)->toBe(11000) // the excise is part of the cost
            ->and($lines['2200']->debit_minor)->toBe(3600)
            ->and($lines['2200']->tax_kind)->toBe(JournalLine::INPUT)
            ->and($lines['2000']->credit_minor)->toBe(34600);
    });
});

it('reports output less input tax plus adjustments, matching the tax account', function () {
    saleWithTax('S-1', '2026-09-05', 10000, 1800);
    saleWithTax('S-2', '2026-09-06', 5000, 900);
    $this->sendEvent('finance.invoice.voided', 'sale:S-2:void', [
        'invoice_number' => 'S-2', 'currency' => 'SSP', 'total_minor' => 5900, 'reason' => 'Mistake',
    ], occurredAt: '2026-09-07T09:00:00+00:00')->assertCreated();
    $this->sendEvent('finance.bill.received', 'purchase:B-1:bill', [
        'bill_number' => 'B-1', 'supplier_name' => 'Wholesaler', 'supplier_reference' => null, 'currency' => 'SSP',
        'total_minor' => 5900, 'tax_minor' => 900, 'received_on' => '2026-09-08', 'due_on' => null,
        'lines' => [['description' => 'Stock', 'quantity' => 1, 'amount_minor' => 5000]],
    ], occurredAt: '2026-09-08T09:00:00+00:00')->assertCreated();
    taxEntry('2026-09-20', '6900', '2200', '1.50', 'Penalty owed')->assertSessionHasNoErrors();

    $this->get(taxUrl('tax-returns?starts_on=2026-09-01&ends_on=2026-09-30'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tax-returns/index')
            ->where('firstReturn', true)
            ->where('preview.output_minor', 1800)
            ->where('preview.input_minor', 900)
            ->where('preview.adjustments_minor', 150)
            ->where('preview.net_minor', 1050));

    expect(taxOwed())->toBe(1050);
});

it('locks a filed period for anything that touches the tax account', function () {
    taxEntry('2026-09-10', '1010', '2200', '18', 'Tax collected')->assertSessionHasNoErrors();

    $this->post(taxUrl('tax-returns'), ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'reference' => 'TR-09'])->assertSessionHasNoErrors();
    $return = $this->asTenant('acme', fn () => TaxReturn::query()->sole());
    expect($return->net_minor)->toBe(1800)->and($return->output_minor)->toBe(0)->and($return->adjustments_minor)->toBe(1800);

    taxEntry('2026-09-15', '1010', '2200', '5')->assertSessionHasErrors(['entry_date' => 'Tax is filed up to 30 Sep 2026; date it 1 Oct 2026 or later.']);
    taxEntry('2026-09-15', '6000', '1010', '5')->assertSessionHasNoErrors(); // no tax: the month is open

    // A product's sale with tax in the filed month moves to the first day after it.
    saleWithTax('S-1', '2026-09-20', 10000, 1800);
    $journal = $this->asTenant('acme', fn () => Journal::query()->where('source_reference', 'S-1')->sole());
    expect($journal->entry_date->toDateString())->toBe('2026-10-01')->and($journal->memo)->toContain('books locked');

    // Returns follow one another, and cover days that are over.
    $this->post(taxUrl('tax-returns'), ['starts_on' => '2026-08-01', 'ends_on' => '2026-10-20'])->assertSessionHasErrors('ends_on');
    $this->post(taxUrl('tax-returns'), ['ends_on' => '2026-10-15'])->assertSessionHasNoErrors();
    $second = $this->asTenant('acme', fn () => TaxReturn::query()->orderByDesc('ends_on')->first());
    expect($second->starts_on->toDateString())->toBe('2026-10-01')->and($second->output_minor)->toBe(1800);
});

it('settles a return from the bank, keeps it out of later returns, and unfiles only the latest', function () {
    taxEntry('2026-09-10', '1010', '2200', '18', 'Tax collected')->assertSessionHasNoErrors();
    $this->post(taxUrl('tax-returns'), ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'])->assertSessionHasNoErrors();
    $september = $this->asTenant('acme', fn () => TaxReturn::query()->sole());

    $settle = fn (string $date) => $this->post(taxUrl("tax-returns/{$september->id}/settle"), ['account_id' => taxAccountId('1010'), 'date' => $date]);
    $settle('2026-09-30')->assertSessionHasErrors('entry_date'); // inside the filed period
    $settle('2026-10-05')->assertSessionHasNoErrors();
    $settle('2026-10-06')->assertSessionHasErrors('return');
    expect(taxOwed())->toBe(0);

    $this->post(taxUrl('tax-returns'), ['ends_on' => '2026-10-15'])->assertSessionHasNoErrors();
    $october = $this->asTenant('acme', fn () => TaxReturn::query()->orderByDesc('ends_on')->first());
    expect($october->net_minor)->toBe(0); // the payment is not October's tax

    $this->get(taxUrl("tax-returns/{$september->id}"))
        ->assertInertia(fn (Assert $page) => $page->component('tax-returns/show')->where('taxReturn.latest', false)->where('changed', false));

    $this->delete(taxUrl("tax-returns/{$september->id}"))->assertSessionHasErrors('return');
    $this->delete(taxUrl("tax-returns/{$october->id}"))->assertRedirect();
    $this->delete(taxUrl("tax-returns/{$september->id}"))->assertRedirect();

    expect($this->asTenant('acme', fn () => TaxReturn::query()->count()))->toBe(0)
        ->and(taxOwed())->toBe(1800); // the payment reversed
    taxEntry('2026-09-15', '1010', '2200', '5')->assertSessionHasNoErrors(); // unlocked again
});

it('exports a return and keeps filing to those who manage tax', function () {
    taxEntry('2026-09-10', '1010', '2200', '18', 'Tax collected')->assertSessionHasNoErrors();
    $this->post(taxUrl('tax-returns'), ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'])->assertSessionHasNoErrors();
    $return = $this->asTenant('acme', fn () => TaxReturn::query()->sole());

    $csv = $this->get(taxUrl("tax-returns/{$return->id}?export=rows"))->assertOk()->streamedContent();
    expect($csv)->toContain('Kind,Code,"Net amount",Tax')->toContain('adjustment,,,18.00');

    $this->actingAsMember('acme', ['finance.reports.view']);
    $this->get(taxUrl('tax-returns'))->assertOk();
    $this->post(taxUrl('tax-returns'), ['ends_on' => '2026-10-15'])->assertForbidden();
    $this->post(taxUrl('tax-codes'), ['code' => 'X', 'name' => 'X', 'rate' => '1'])->assertForbidden();
});
