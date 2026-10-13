<?php

namespace App\Finance;

use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What customers owe: invoices raised here (product sales arrive as events)
 * and the payments received against them.
 */
class Receivables
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * Dr accounts receivable / Cr each line's revenue account (+ tax payable).
     *
     * @param  list<array{account: Account, description: string, amount_minor: int}>  $lines
     */
    public function raise(string $customerName, CarbonImmutable $issuedOn, ?CarbonImmutable $dueOn, array $lines, int $taxMinor, User $by): Invoice
    {
        return DB::transaction(function () use ($customerName, $issuedOn, $dueOn, $lines, $taxMinor, $by) {
            $number = $this->numbers->next('invoice', 'INV');
            $total = array_sum(array_column($lines, 'amount_minor')) + $taxMinor;

            $journalLines = [['account' => Account::system(Account::RECEIVABLE), 'debit' => $total, 'description' => $customerName]];
            foreach ($lines as $line) {
                $journalLines[] = ['account' => $line['account'], 'credit' => $line['amount_minor'], 'description' => $line['description']];
            }
            if ($taxMinor > 0) {
                $journalLines[] = ['account' => Account::system(Account::TAX), 'credit' => $taxMinor];
            }

            $journal = $this->ledger->post($issuedOn, "Invoice {$number} to {$customerName}", $journalLines, [
                'source_type' => 'invoice',
                'source_reference' => $number,
            ], $by);

            return Invoice::query()->create([
                'number' => $number,
                'source_app' => 'finance',
                'customer_name' => $customerName,
                'issued_on' => $issuedOn,
                'due_on' => $dueOn,
                'total_minor' => $total,
                'tax_minor' => $taxMinor,
                'lines' => array_map(fn (array $line) => [
                    'description' => $line['description'],
                    'quantity' => null,
                    'amount_minor' => $line['amount_minor'],
                    'account' => $line['account']->code,
                ], $lines),
                'journal_id' => $journal->id,
            ]);
        });
    }

    /**
     * Dr the bank or cash account / Cr accounts receivable.
     */
    public function receive(Invoice $invoice, Account $into, PaymentMethod $method, int $amountMinor, CarbonImmutable $receivedOn, ?string $memo, User $by): CustomerPayment
    {
        if (! $into->isBank()) {
            throw ValidationException::withMessages(['account_id' => 'Receive into a bank or cash account.']);
        }

        return DB::transaction(function () use ($invoice, $into, $method, $amountMinor, $receivedOn, $memo, $by) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->voided_at !== null) {
                throw ValidationException::withMessages(['amount' => "Invoice {$locked->number} is void."]);
            }
            if ($amountMinor <= 0 || $amountMinor > $locked->outstanding()) {
                throw ValidationException::withMessages(['amount' => 'The amount must be more than zero and no more than what is outstanding.']);
            }

            $number = $this->numbers->next('receipt', 'RCT');
            $journal = $this->ledger->post($receivedOn, "Receipt {$number} for invoice {$locked->number}", [
                ['account' => $into, 'debit' => $amountMinor],
                ['account' => Account::system(Account::RECEIVABLE), 'credit' => $amountMinor, 'description' => $locked->customer_name],
            ], ['source_type' => 'receipt', 'source_reference' => $number], $by);

            $payment = CustomerPayment::query()->create([
                'number' => $number,
                'source_app' => 'finance',
                'kind' => CustomerPayment::RECEIPT,
                'invoice_id' => $locked->id,
                'invoice_number' => $locked->number,
                'method' => $method,
                'amount_minor' => $amountMinor,
                'received_on' => $receivedOn,
                'account_id' => $into->id,
                'memo' => $memo,
                'journal_id' => $journal->id,
                'created_by' => $by->id,
            ]);

            $locked->forceFill(['paid_minor' => $locked->paid_minor + $amountMinor])->save();

            return $payment;
        });
    }

    /**
     * Voids an unpaid invoice raised here: its journal is reversed.
     * Products void their own invoices with an event.
     */
    public function void(Invoice $invoice, string $reason, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $by) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->source_app !== 'finance') {
                throw ValidationException::withMessages(['reason' => "Invoice {$locked->number} came from {$locked->source_app}; void it there."]);
            }
            if ($locked->voided_at !== null || $locked->paid_minor > 0) {
                throw ValidationException::withMessages(['reason' => "Invoice {$locked->number} is void or has payments."]);
            }

            $reversal = $locked->journal_id === null ? null : $this->ledger->reverse(
                Journal::query()->findOrFail($locked->journal_id),
                CarbonImmutable::today(),
                "Void of invoice {$locked->number}: {$reason}",
                $by,
            );

            $locked->forceFill(['voided_at' => now(), 'void_reason' => $reason, 'void_journal_id' => $reversal?->id])->save();

            return $locked;
        });
    }
}
