<?php

namespace App\Finance;

use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\Bill;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Supplier;
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
 *   finance.bill.received     Dr inventory (or the line's account) (+ tax)
 *                                                  Cr payable
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
            'finance.bill.received.v1' => $this->billReceived($event),
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

        $credits = $this->split($lines, $total - $tax, Account::SALES);
        $journalLines = [['account' => Account::system(Account::RECEIVABLE), 'debit' => $total, 'description' => $p['customer_reference'] ?? null]];
        foreach ($credits as [$account, $amount]) {
            $journalLines[] = ['account' => $account, 'credit' => $amount];
        }
        if ($tax > 0) {
            $journalLines[] = ['account' => Account::system(Account::TAX), 'credit' => $tax];
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

        // A zero total posts nothing; the invoice is still kept for voids.
        if ($total > 0) {
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
     * @return list<array{description: string, quantity: int|float|null, amount_minor: int, account: string|null}>
     */
    private function lines(array $lines, string $accountKey): array
    {
        return array_values(array_map(fn ($line) => [
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => $line['quantity'] ?? null,
            'amount_minor' => (int) ($line['amount_minor'] ?? 0),
            'account' => isset($line[$accountKey]) ? (string) $line[$accountKey] : null,
        ], array_filter($lines, 'is_array')));
    }

    /**
     * The amount split across the accounts the lines name (by code), the
     * rest of it (or all of it, when the lines do not add up) to the default.
     *
     * @param  list<array{description: string, quantity: int|float|null, amount_minor: int, account: string|null}>  $lines
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
