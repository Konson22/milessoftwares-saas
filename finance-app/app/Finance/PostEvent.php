<?php

namespace App\Finance;

use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\Bill;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Supplier;
use App\Models\SupplierCredit;
use Carbon\CarbonImmutable;
use Miles\SharedModuleKit\Envelope;

/**
 * Turns a product's business event into the books, inside the inbox's
 * transaction (so it happens exactly once per business transaction).
 *
 *   finance.invoice.issued    Dr receivable        Cr revenue (+ tax payable)
 *   finance.payment.received  Dr cash/bank/...     Cr receivable
 *   finance.invoice.voided    Dr revenue (+ tax)   Cr receivable, and a refund:
 *                             Dr receivable        Cr cash/bank/...
 *   finance.sales_return.issued
 *                             Dr revenue (+ tax)   Cr receivable, set against its invoice,
 *                             and a refund: Dr receivable  Cr cash/bank/...
 *   finance.stock.adjusted    opening: Dr inventory  Cr owner's equity;
 *                             count, write_off: Dr stock losses  Cr inventory
 *                             (a gain the other way round)
 *   finance.cash.counted      short: Dr cash over and short  Cr cash (over the reverse)
 *   finance.bill.received     Dr inventory (or the line's account) (+ tax)
 *                                                  Cr payable
 *   finance.credit_note.received
 *                             Dr payable           Cr inventory (or the line's account) (+ tax),
 *                                                  set against its bill
 *
 * Sales carrying a cost also move it: Dr cost of goods sold / Cr inventory
 * (returns put back on the shelf, the other way round).
 */
class PostEvent
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * @throws NotYetPostable when it depends on an event that has not arrived (the product retries)
     * @throws UnsupportedEvent for an event type or version Finance does not handle
     */
    public function handle(Envelope $event): void
    {
        // An organization entitled before its chart existed gets it now.
        $this->chart->ensure();

        match ("{$event->eventType}.v{$event->schemaVersion}") {
            'finance.invoice.issued.v1' => $this->invoiceIssued($event),
            'finance.payment.received.v1' => $this->paymentReceived($event),
            'finance.invoice.voided.v1' => $this->invoiceVoided($event),
            'finance.sales_return.issued.v1' => $this->salesReturnIssued($event),
            'finance.stock.adjusted.v1' => $this->stockAdjusted($event),
            'finance.cash.counted.v1' => $this->cashCounted($event),
            'finance.bill.received.v1' => $this->billReceived($event),
            'finance.credit_note.received.v1' => $this->creditNoteReceived($event),
            default => throw new UnsupportedEvent("Finance does not handle {$event->eventType} v{$event->schemaVersion}."),
        };
    }

    private function invoiceIssued(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::instance($event->occurredAt)->startOfDay();
        $total = (int) $p['total_minor'];
        $tax = (int) ($p['tax_minor'] ?? 0);
        $lines = $this->lines($p['lines'] ?? [], 'revenue_account');

        $journalLines = [];
        if ($total > 0) {
            $journalLines[] = ['account' => Account::system(Account::RECEIVABLE), 'debit' => $total, 'description' => $p['customer_reference'] ?? null];
            foreach ($this->split($lines, $total - $tax, Account::SALES) as [$account, $amount]) {
                $journalLines[] = ['account' => $account, 'credit' => $amount];
            }
            if ($tax > 0) {
                $journalLines[] = ['account' => Account::system(Account::TAX), 'credit' => $tax];
            }
        }

        // The goods leave inventory at what they cost: cost of goods sold.
        $cost = array_sum(array_map(fn (array $line) => $line['cost_minor'] ?? 0, $lines));
        if ($cost > 0) {
            $journalLines[] = ['account' => Account::system(Account::PURCHASES), 'debit' => $cost, 'description' => 'Cost of goods sold'];
            $journalLines[] = ['account' => Account::system(Account::INVENTORY), 'credit' => $cost];
        }

        $invoice = new Invoice([
            'number' => $p['invoice_number'],
            'source_app' => $event->sourceApp,
            'customer_name' => $p['customer_reference'] ?? null,
            'branch_id' => $p['branch_id'] ?? null,
            'issued_on' => $date,
            'due_on' => $p['due_on'] ?? null,
            'total_minor' => $total,
            'tax_minor' => $tax,
            'lines' => $lines,
        ]);

        // Nothing charged and nothing at cost posts nothing; the invoice is
        // still kept for voids.
        if ($journalLines !== []) {
            $invoice->journal_id = $this->ledger->post($date, "Invoice {$p['invoice_number']}", $journalLines, $this->source($event, $p['invoice_number']))->id;
        }
        $invoice->save();

        // Payments that arrived first.
        $early = CustomerPayment::query()
            ->where('source_app', $event->sourceApp)
            ->where('invoice_number', $invoice->number)
            ->whereNull('invoice_id')
            ->get();
        foreach ($early as $payment) {
            $payment->forceFill(['invoice_id' => $invoice->id])->save();
        }
        $this->refreshPaid($invoice);
    }

    private function paymentReceived(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::instance($event->occurredAt)->startOfDay();
        $amount = (int) $p['amount_minor'];
        $method = PaymentMethod::from($p['method']);
        $account = $this->moneyAccount($method);
        $invoice = isset($p['invoice_number'])
            ? Invoice::query()->where('source_app', $event->sourceApp)->where('number', $p['invoice_number'])->first()
            : null;

        $journal = $this->ledger->post($date, "Payment {$p['payment_reference']}", [
            ['account' => $account, 'debit' => $amount],
            ['account' => Account::system(Account::RECEIVABLE), 'credit' => $amount],
        ], $this->source($event, $p['payment_reference']));

        CustomerPayment::query()->create([
            'number' => $p['payment_reference'],
            'source_app' => $event->sourceApp,
            'kind' => CustomerPayment::RECEIPT,
            'invoice_id' => $invoice?->id,
            'invoice_number' => $p['invoice_number'] ?? null,
            'method' => $method,
            'amount_minor' => $amount,
            'received_on' => $date,
            'account_id' => $account->id,
            'processor_reference' => $p['processor_reference'] ?? null,
            'journal_id' => $journal->id,
        ]);

        if ($invoice !== null) {
            $this->refreshPaid($invoice);
        }
    }

    private function invoiceVoided(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::instance($event->occurredAt)->startOfDay();
        $invoice = Invoice::query()->where('source_app', $event->sourceApp)->where('number', $p['invoice_number'])->lockForUpdate()->first();

        if ($invoice === null) {
            throw new NotYetPostable("Invoice {$p['invoice_number']} has not arrived yet.");
        }
        if ($invoice->voided_at !== null) {
            return;
        }

        // The invoice's own journal, mirrored.
        $voidJournal = $invoice->journal_id === null ? null : $this->ledger->reverse(
            Journal::query()->findOrFail($invoice->journal_id),
            $date,
            "Void of invoice {$invoice->number}: {$p['reason']}",
            sourceType: $event->eventType,
        );
        $invoice->forceFill([
            'voided_at' => CarbonImmutable::instance($event->occurredAt),
            'void_reason' => $p['reason'],
            'void_journal_id' => $voidJournal?->id,
        ])->save();

        $refund = $p['refund'] ?? null;
        if (is_array($refund) && (int) $refund['amount_minor'] > 0) {
            $method = PaymentMethod::from($refund['method']);
            $account = $this->moneyAccount($method);
            $amount = (int) $refund['amount_minor'];
            $reference = ($refund['payment_reference'] ?? $invoice->number).'-REFUND';

            $journal = $this->ledger->post($date, "Refund for invoice {$invoice->number}", [
                ['account' => Account::system(Account::RECEIVABLE), 'debit' => $amount],
                ['account' => $account, 'credit' => $amount],
            ], $this->source($event, $reference));

            CustomerPayment::query()->create([
                'number' => $reference,
                'source_app' => $event->sourceApp,
                'kind' => CustomerPayment::REFUND,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'method' => $method,
                'amount_minor' => $amount,
                'received_on' => $date,
                'account_id' => $account->id,
                'memo' => $p['reason'],
                'journal_id' => $journal->id,
            ]);
            $this->refreshPaid($invoice);
        }
    }

    /**
     * Part of a sale brought back: the revenue reversed against its
     * invoice, the cost of what goes back on the shelf back to inventory,
     * and the refund.
     */
    private function salesReturnIssued(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::instance($event->occurredAt)->startOfDay();
        $invoice = Invoice::query()->where('source_app', $event->sourceApp)->where('number', $p['invoice_number'])->lockForUpdate()->first();

        if ($invoice === null) {
            throw new NotYetPostable("Invoice {$p['invoice_number']} has not arrived yet.");
        }
        if ($invoice->voided_at !== null) {
            return;
        }

        $total = (int) $p['total_minor'];
        $tax = (int) ($p['tax_minor'] ?? 0);
        $lines = $this->lines($p['lines'] ?? [], 'revenue_account');

        $journalLines = [];
        if ($total > 0) {
            foreach ($this->split($lines, $total - $tax, Account::SALES) as [$account, $amount]) {
                $journalLines[] = ['account' => $account, 'debit' => $amount];
            }
            if ($tax > 0) {
                $journalLines[] = ['account' => Account::system(Account::TAX), 'debit' => $tax];
            }
            $journalLines[] = ['account' => Account::system(Account::RECEIVABLE), 'credit' => $total, 'description' => $invoice->customer_name];
        }
        $cost = array_sum(array_map(fn (array $line) => $line['cost_minor'] ?? 0, $lines));
        if ($cost > 0) {
            $journalLines[] = ['account' => Account::system(Account::INVENTORY), 'debit' => $cost, 'description' => 'Returned to stock'];
            $journalLines[] = ['account' => Account::system(Account::PURCHASES), 'credit' => $cost];
        }
        if ($journalLines !== []) {
            $this->ledger->post($date, "Return {$p['return_number']} on invoice {$invoice->number}: {$p['reason']}", $journalLines, $this->source($event, $p['return_number']));
        }
        $invoice->forceFill(['credited_minor' => $invoice->credited_minor + $total])->save();

        $refund = $p['refund'] ?? null;
        if (is_array($refund) && (int) $refund['amount_minor'] > 0) {
            $method = PaymentMethod::from($refund['method']);
            $account = $this->moneyAccount($method);
            $amount = (int) $refund['amount_minor'];
            $reference = $refund['payment_reference'] ?? $p['return_number'];

            $journal = $this->ledger->post($date, "Refund for return {$p['return_number']}", [
                ['account' => Account::system(Account::RECEIVABLE), 'debit' => $amount],
                ['account' => $account, 'credit' => $amount],
            ], $this->source($event, "{$reference}-REFUND"));

            CustomerPayment::query()->create([
                'number' => $reference,
                'source_app' => $event->sourceApp,
                'kind' => CustomerPayment::REFUND,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'method' => $method,
                'amount_minor' => $amount,
                'received_on' => $date,
                'account_id' => $account->id,
                'memo' => $p['reason'],
                'journal_id' => $journal->id,
            ]);
        }
        $this->refreshPaid($invoice);
    }

    /**
     * Stock found, lost or destroyed, or on the shelf from before: at cost,
     * against inventory.
     */
    private function stockAdjusted(Envelope $event): void
    {
        $p = $event->payload;
        $amount = (int) $p['amount_minor'];
        if ($amount === 0) {
            return;
        }

        $other = Account::system($p['kind'] === 'opening' ? Account::EQUITY : Account::STOCK_LOSSES);
        $inventory = Account::system(Account::INVENTORY);
        [$debit, $credit] = $amount > 0 ? [$inventory, $other] : [$other, $inventory];
        $label = match ($p['kind']) {
            'opening' => 'Opening stock',
            'write_off' => 'Stock written off',
            default => 'Stock count',
        };

        $this->ledger->post(CarbonImmutable::parse($p['adjusted_on']), "{$label} {$p['reference']}: {$p['reason']}", [
            ['account' => $debit, 'debit' => abs($amount)],
            ['account' => $credit, 'credit' => abs($amount)],
        ], $this->source($event, $p['reference']));
    }

    /**
     * A drawer that held more or less cash than it should.
     */
    private function cashCounted(Envelope $event): void
    {
        $p = $event->payload;
        $difference = (int) $p['difference_minor'];
        if ($difference === 0) {
            return;
        }

        $cash = Account::system(Account::CASH);
        $overShort = Account::system(Account::CASH_OVER_SHORT);
        [$debit, $credit] = $difference > 0 ? [$cash, $overShort] : [$overShort, $cash];

        $this->ledger->post(CarbonImmutable::parse($p['counted_on']), ($difference > 0 ? 'Cash over' : 'Cash short')." in {$p['reference']}".(isset($p['note']) ? ": {$p['note']}" : ''), [
            ['account' => $debit, 'debit' => abs($difference)],
            ['account' => $credit, 'credit' => abs($difference)],
        ], $this->source($event, $p['reference']));
    }

    private function billReceived(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::parse($p['received_on']);
        $total = (int) $p['total_minor'];
        $tax = (int) ($p['tax_minor'] ?? 0);
        $lines = $this->lines($p['lines'] ?? [], 'expense_account');

        $supplier = isset($p['supplier_id'])
            ? Supplier::query()->firstOrCreate(
                ['source_app' => $event->sourceApp, 'source_supplier_id' => $p['supplier_id']],
                ['name' => $p['supplier_name']],
            )
            : Supplier::query()->firstOrCreate(['name' => $p['supplier_name'], 'source_app' => null]);

        $journalLines = [];
        foreach ($this->split($lines, $total - $tax, Account::INVENTORY) as [$account, $amount]) {
            $journalLines[] = ['account' => $account, 'debit' => $amount];
        }
        if ($tax > 0) {
            $journalLines[] = ['account' => Account::system(Account::TAX), 'debit' => $tax];
        }
        $journalLines[] = ['account' => Account::system(Account::PAYABLE), 'credit' => $total, 'description' => $supplier->name];

        $journal = $total > 0
            ? $this->ledger->post($date, "Bill {$p['bill_number']} from {$supplier->name}", $journalLines, $this->source($event, $p['bill_number']))
            : null;

        Bill::query()->create([
            'supplier_id' => $supplier->id,
            'number' => $p['bill_number'],
            'supplier_reference' => $p['supplier_reference'] ?? null,
            'source_app' => $event->sourceApp,
            'branch_id' => $p['branch_id'] ?? null,
            'bill_date' => $date,
            'due_on' => $p['due_on'] ?? null,
            'total_minor' => $total,
            'tax_minor' => $tax,
            'lines' => $lines,
            'journal_id' => $journal?->id,
        ]);
    }

    /**
     * Goods sent back: Dr payable / Cr inventory (or the line's account),
     * and the credit set against the bill it came from.
     */
    private function creditNoteReceived(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::parse($p['credited_on']);
        $total = (int) $p['total_minor'];
        $tax = (int) ($p['tax_minor'] ?? 0);
        $lines = $this->lines($p['lines'] ?? [], 'expense_account');

        $bill = null;
        if (isset($p['bill_number'])) {
            $bill = Bill::query()->where('source_app', $event->sourceApp)->where('number', $p['bill_number'])->lockForUpdate()->first();

            if ($bill === null) {
                throw new NotYetPostable("Bill {$p['bill_number']} has not arrived yet.");
            }
        }

        $supplier = $bill?->supplier()->firstOrFail() ?? (isset($p['supplier_id'])
            ? Supplier::query()->firstOrCreate(
                ['source_app' => $event->sourceApp, 'source_supplier_id' => $p['supplier_id']],
                ['name' => $p['supplier_name']],
            )
            : Supplier::query()->firstOrCreate(['name' => $p['supplier_name'], 'source_app' => null]));

        $journal = null;
        if ($total > 0) {
            $journalLines = [['account' => Account::system(Account::PAYABLE), 'debit' => $total, 'description' => $supplier->name]];
            foreach ($this->split($lines, $total - $tax, Account::INVENTORY) as [$account, $amount]) {
                $journalLines[] = ['account' => $account, 'credit' => $amount];
            }
            if ($tax > 0) {
                $journalLines[] = ['account' => Account::system(Account::TAX), 'credit' => $tax];
            }

            $journal = $this->ledger->post($date, "Credit {$p['credit_note_number']} from {$supplier->name}: {$p['reason']}", $journalLines, $this->source($event, $p['credit_note_number']));
        }

        $applied = $bill === null ? 0 : min($total, $bill->outstanding());
        $bill?->forceFill(['paid_minor' => $bill->paid_minor + $applied])->save();

        SupplierCredit::query()->create([
            'supplier_id' => $supplier->id,
            'number' => $p['credit_note_number'],
            'source_app' => $event->sourceApp,
            'bill_id' => $bill?->id,
            'branch_id' => $p['branch_id'] ?? null,
            'credited_on' => $date,
            'total_minor' => $total,
            'tax_minor' => $tax,
            'applied_minor' => $applied,
            'reason' => $p['reason'],
            'lines' => $lines,
            'journal_id' => $journal?->id,
        ]);
    }

    /**
     * Which account takes a payment made this way.
     */
    public function moneyAccount(PaymentMethod $method): Account
    {
        return Account::system(match ($method) {
            PaymentMethod::MobileMoney => Account::MOBILE_MONEY,
            PaymentMethod::Card => Account::CARD,
            PaymentMethod::BankTransfer => Account::BANK,
            PaymentMethod::Cash, PaymentMethod::Other => Account::CASH,
        });
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return list<array{description: string, quantity: int|float|null, amount_minor: int, account: string|null, cost_minor: int|null}>
     */
    private function lines(array $lines, string $accountKey): array
    {
        return array_values(array_map(fn ($line) => [
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => $line['quantity'] ?? null,
            'amount_minor' => (int) ($line['amount_minor'] ?? 0),
            'account' => isset($line[$accountKey]) ? (string) $line[$accountKey] : null,
            'cost_minor' => isset($line['cost_minor']) ? max(0, (int) $line['cost_minor']) : null,
        ], array_filter($lines, 'is_array')));
    }

    /**
     * The amount split across the accounts the lines name (by code), the
     * rest of it (or all of it, when the lines do not add up) to the default.
     *
     * @param  list<array{description: string, quantity: int|float|null, amount_minor: int, account: string|null, cost_minor: int|null}>  $lines
     * @return list<array{0: Account, 1: int}>
     */
    private function split(array $lines, int $amount, string $defaultKey): array
    {
        $named = [];
        if (array_sum(array_column($lines, 'amount_minor')) === $amount) {
            foreach ($lines as $line) {
                $account = $line['account'] === null ? null : Account::query()->where('code', $line['account'])->where('active', true)->first();
                if ($account !== null) {
                    $named[$account->id] ??= [$account, 0];
                    $named[$account->id][1] += $line['amount_minor'];
                }
            }
        }

        $rest = $amount - array_sum(array_column($named, 1));
        $split = array_values(array_filter($named, fn (array $part) => $part[1] > 0));
        if ($rest > 0) {
            array_unshift($split, [Account::system($defaultKey), $rest]);
        }

        return $split;
    }

    /**
     * @return array{source_app: string, source_type: string, source_reference: string, branch_id: string|null}
     */
    private function source(Envelope $event, string $reference): array
    {
        return [
            'source_app' => $event->sourceApp,
            'source_type' => $event->eventType,
            'source_reference' => $reference,
            'branch_id' => $event->payload['branch_id'] ?? null,
        ];
    }

    private function refreshPaid(Invoice $invoice): void
    {
        $paid = (int) CustomerPayment::query()->where('invoice_id', $invoice->id)->get()->sum(fn (CustomerPayment $payment) => $payment->signedAmount());
        $invoice->forceFill(['paid_minor' => $paid])->save();
    }
}
