<?php

namespace App\Finance;

use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\CustomerPayment;
use App\Models\DimensionValue;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\TaxCode;
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
        private readonly Currencies $currencies,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * Dr accounts receivable / Cr each line's revenue account (+ output tax,
     * one line per tax code). $taxMinor is tax without a code, kept for
     * entries made before tax codes.
     *
     * In another currency ($foreign), the amounts are in it and each line is
     * converted at the rate; the receivable keeps the total in the currency.
     *
     * @param  list<array{account: Account, description: string, amount_minor: int, tax_code?: TaxCode|null, cost_centre?: DimensionValue|null, project?: DimensionValue|null}>  $lines
     * @param  array{currency: string, rate: string}|null  $foreign
     */
    public function raise(string $customerName, CarbonImmutable $issuedOn, ?CarbonImmutable $dueOn, array $lines, int $taxMinor, User $by, ?array $foreign = null): Invoice
    {
        return DB::transaction(function () use ($customerName, $issuedOn, $dueOn, $lines, $taxMinor, $by, $foreign) {
            $number = $this->numbers->next('invoice', 'INV');
            $books = fn (int $minor): int => $foreign === null ? $minor : Currencies::toBooks($minor, $foreign['currency'], $foreign['rate']);
            $byCode = Tax::byCode($lines);
            $codedTax = array_sum(array_column($byCode, 'tax'));
            $currencyTotal = array_sum(array_column($lines, 'amount_minor')) + $codedTax + $taxMinor;

            $credits = [];
            foreach ($lines as $line) {
                $credits[] = ['account' => $line['account'], 'credit' => $books($line['amount_minor']), 'description' => $line['description'], 'cost_centre' => $line['cost_centre'] ?? null, 'project' => $line['project'] ?? null];
            }
            foreach (Tax::journalLines($byCode, JournalLine::OUTPUT) as $taxLine) {
                $taxLine['credit'] = $books($taxLine['credit'] ?? 0);
                $taxLine['tax']['base'] = $books($taxLine['tax']['base']);
                $credits[] = $taxLine;
            }
            if ($taxMinor > 0) {
                $credits[] = ['account' => Account::system(Account::TAX), 'credit' => $books($taxMinor)];
            }
            // The receivable is the sum of the converted lines, so the journal balances.
            $total = array_sum(array_column($credits, 'credit'));
            $journalLines = [[
                'account' => Account::system(Account::RECEIVABLE), 'debit' => $total, 'description' => $customerName,
                'currency' => $foreign === null ? null : ['code' => $foreign['currency'], 'amount' => $currencyTotal],
            ], ...$credits];

            $journal = $this->ledger->post($issuedOn, "Invoice {$number} to {$customerName}", $journalLines, [
                'source_type' => 'invoice',
                'require_dimensions' => true,
                'source_reference' => $number,
            ], $by);

            return Invoice::query()->create([
                'number' => $number,
                'source_app' => 'finance',
                'customer_name' => $customerName,
                'issued_on' => $issuedOn,
                'due_on' => $dueOn,
                'total_minor' => $total,
                'tax_minor' => array_sum(array_map(fn (array $line) => $line['account']->system_key === Account::TAX ? $line['credit'] : 0, $credits)),
                'currency' => $foreign['currency'] ?? null,
                'exchange_rate' => $foreign['rate'] ?? null,
                'currency_total_minor' => $foreign === null ? null : $currencyTotal,
                // In the invoice's currency.
                'lines' => array_map(fn (array $line) => [
                    'description' => $line['description'],
                    'quantity' => null,
                    'amount_minor' => $line['amount_minor'],
                    'account' => $line['account']->code,
                    'cost_minor' => null,
                    'tax_code' => ($line['tax_code'] ?? null)?->code,
                    'tax_minor' => ($line['tax_code'] ?? null)?->taxOn($line['amount_minor']),
                ], $lines),
                'journal_id' => $journal->id,
            ]);
        });
    }

    /**
     * Dr the bank or cash account / Cr accounts receivable.
     *
     * $amountMinor is in the invoice's currency. For an invoice in another
     * currency it is converted at $rate (or the day's rate); the receivable
     * is cleared at the invoice's rate (all that is left, when this pays it
     * in full), and the difference is a realised exchange gain or loss. The
     * money goes into an account in the invoice's currency or the books'.
     */
    public function receive(Invoice $invoice, Account $into, PaymentMethod $method, int $amountMinor, CarbonImmutable $receivedOn, ?string $memo, User $by, ?string $rate = null): CustomerPayment
    {
        if (! $into->isBank()) {
            throw ValidationException::withMessages(['account_id' => 'Receive into a bank or cash account.']);
        }
        if ($into->currency !== null && $into->currency !== $invoice->currency) {
            throw ValidationException::withMessages(['account_id' => "{$into->label()} is held in {$into->currency}; the invoice is in ".($invoice->currency ?? Money::currency()).'.']);
        }

        return DB::transaction(function () use ($invoice, $into, $method, $amountMinor, $receivedOn, $memo, $by, $rate) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->voided_at !== null) {
                throw ValidationException::withMessages(['amount' => "Invoice {$locked->number} is void."]);
            }
            if ($amountMinor <= 0 || $amountMinor > $locked->currencyOutstanding()) {
                throw ValidationException::withMessages(['amount' => 'The amount must be more than zero and no more than what is outstanding.']);
            }

            $currency = $locked->currency;
            $rate = $currency === null ? null : ($rate ?? $this->currencies->rate($currency, $receivedOn));
            $received = $currency === null ? $amountMinor : Currencies::toBooks($amountMinor, $currency, (string) $rate);
            $cleared = match (true) {
                $currency === null => $amountMinor,
                $amountMinor === $locked->currencyOutstanding() => $locked->outstanding(),
                default => min($locked->outstanding(), Currencies::toBooks($amountMinor, $currency, (string) $locked->exchange_rate)),
            };
            $fx = $received - $cleared;
            $tagged = $currency === null ? null : ['code' => $currency, 'amount' => $amountMinor];

            $number = $this->numbers->next('receipt', 'RCT');
            $lines = [
                ['account' => $into, 'debit' => $received, 'currency' => $into->currency === null ? null : $tagged],
                ['account' => Account::system(Account::RECEIVABLE), 'credit' => $cleared, 'description' => $locked->customer_name, 'currency' => $tagged],
            ];
            if ($fx !== 0) {
                $this->chart->ensure();
                $lines[] = ['account' => Account::system(Account::FX), $fx > 0 ? 'credit' : 'debit' => abs($fx), 'description' => "Exchange difference on invoice {$locked->number}"];
            }
            $journal = $this->ledger->post($receivedOn, "Receipt {$number} for invoice {$locked->number}", $lines, ['source_type' => 'receipt', 'source_reference' => $number], $by);

            $payment = CustomerPayment::query()->create([
                'number' => $number,
                'source_app' => 'finance',
                'kind' => CustomerPayment::RECEIPT,
                'invoice_id' => $locked->id,
                'invoice_number' => $locked->number,
                'method' => $method,
                'amount_minor' => $received,
                'received_on' => $receivedOn,
                'account_id' => $into->id,
                'memo' => $memo,
                'journal_id' => $journal->id,
                'created_by' => $by->id,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'currency_amount_minor' => $currency === null ? null : $amountMinor,
                'fx_minor' => $fx,
            ]);

            $locked->forceFill([
                'paid_minor' => $locked->paid_minor + $cleared,
                'currency_paid_minor' => $locked->currency_paid_minor + ($currency === null ? 0 : $amountMinor),
            ])->save();

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
