<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\Bill;
use App\Models\DimensionValue;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\TaxCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the organization owes suppliers: bills entered here (product bills
 * arrive as events) and payments set against them.
 */
class Payables
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly DocumentNumbers $numbers,
        private readonly Currencies $currencies,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * Dr each line's account (+ input tax, one line per recoverable tax
     * code) / Cr accounts payable. Tax that cannot be claimed back is part
     * of the line's cost. $taxMinor is tax without a code, kept for entries
     * made before tax codes.
     *
     * In another currency ($foreign), the amounts are in it and each line is
     * converted at the rate; the payable keeps the total in the currency.
     *
     * @param  list<array{account: Account, description: string, amount_minor: int, tax_code?: TaxCode|null, cost_centre?: DimensionValue|null, project?: DimensionValue|null}>  $lines
     * @param  array{currency: string, rate: string}|null  $foreign
     */
    public function recordBill(Supplier $supplier, CarbonImmutable $billDate, ?CarbonImmutable $dueOn, ?string $supplierReference, array $lines, int $taxMinor, ?string $memo, User $by, ?array $foreign = null): Bill
    {
        return DB::transaction(function () use ($supplier, $billDate, $dueOn, $supplierReference, $lines, $taxMinor, $memo, $by, $foreign) {
            $number = $this->numbers->next('bill', 'BILL');
            $books = fn (int $minor): int => $foreign === null ? $minor : Currencies::toBooks($minor, $foreign['currency'], $foreign['rate']);
            $lineTax = fn (array $line): int => ($line['tax_code'] ?? null)?->taxOn($line['amount_minor']) ?? 0;
            $recoverable = fn (array $line): bool => ($line['tax_code'] ?? null)->recoverable ?? false;
            $codedTax = array_sum(array_map($lineTax, $lines));
            $currencyTotal = array_sum(array_column($lines, 'amount_minor')) + $codedTax + $taxMinor;

            $debits = array_map(fn (array $line) => [
                'account' => $line['account'],
                'debit' => $books($line['amount_minor'] + ($recoverable($line) ? 0 : $lineTax($line))),
                'description' => $line['description'],
                'cost_centre' => $line['cost_centre'] ?? null,
                'project' => $line['project'] ?? null,
            ], $lines);
            foreach (Tax::journalLines(Tax::byCode(array_values(array_filter($lines, $recoverable))), JournalLine::INPUT) as $taxLine) {
                $taxLine['debit'] = $books($taxLine['debit'] ?? 0);
                $taxLine['tax']['base'] = $books($taxLine['tax']['base']);
                $debits[] = $taxLine;
            }
            if ($taxMinor > 0) {
                $debits[] = ['account' => Account::system(Account::TAX), 'debit' => $books($taxMinor)];
            }
            // The payable is the sum of the converted lines, so the journal balances.
            $total = array_sum(array_column($debits, 'debit'));
            $journalLines = [...$debits, [
                'account' => Account::system(Account::PAYABLE), 'credit' => $total, 'description' => $supplier->name,
                'currency' => $foreign === null ? null : ['code' => $foreign['currency'], 'amount' => $currencyTotal],
            ]];

            $journal = $this->ledger->post($billDate, "Bill {$number} from {$supplier->name}", $journalLines, [
                'source_type' => 'bill',
                'require_dimensions' => true,
                'source_reference' => $number,
            ], $by);

            return Bill::query()->create([
                'supplier_id' => $supplier->id,
                'number' => $number,
                'supplier_reference' => $supplierReference,
                'source_app' => 'finance',
                'bill_date' => $billDate,
                'due_on' => $dueOn,
                'total_minor' => $total,
                // All tax on the bill, recoverable or not, in the books' currency.
                'tax_minor' => $books($codedTax + $taxMinor),
                'currency' => $foreign['currency'] ?? null,
                'exchange_rate' => $foreign['rate'] ?? null,
                'currency_total_minor' => $foreign === null ? null : $currencyTotal,
                // In the bill's currency.
                'lines' => array_map(fn (array $line) => [
                    'description' => $line['description'],
                    'quantity' => null,
                    'amount_minor' => $line['amount_minor'],
                    'account' => $line['account']->code,
                    'cost_minor' => null,
                    'tax_code' => ($line['tax_code'] ?? null)?->code,
                    'tax_minor' => ($line['tax_code'] ?? null)?->taxOn($line['amount_minor']),
                ], $lines),
                'memo' => $memo,
                'journal_id' => $journal->id,
            ]);
        });
    }

    /**
     * Dr accounts payable / Cr the bank or cash account it was paid from,
     * set against the supplier's bills (what is left over stays on account).
     *
     * A payment from an account held in another currency, or against bills
     * in one, is in that currency and set in full against bills in it. It is
     * converted at $rate (or the day's rate); each bill is cleared at its own
     * rate (all that is left, when paid in full), and the difference is a
     * realised exchange gain or loss.
     *
     * @param  array<string, int>  $allocations  bill id => amount, in the payment's currency
     */
    public function pay(Supplier $supplier, Account $from, int $amountMinor, CarbonImmutable $paidOn, array $allocations, ?string $reference, ?string $memo, User $by, ?string $rate = null): SupplierPayment
    {
        if (! $from->isBank()) {
            throw ValidationException::withMessages(['account_id' => 'Pay from a bank or cash account.']);
        }
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be more than zero.']);
        }
        $allocations = array_filter($allocations, fn (int $amount) => $amount > 0);
        if (array_sum($allocations) > $amountMinor) {
            throw ValidationException::withMessages(['allocations' => 'More is set against bills than was paid.']);
        }

        return DB::transaction(function () use ($supplier, $from, $amountMinor, $paidOn, $allocations, $reference, $memo, $by, $rate) {
            $bills = Bill::query()->whereIn('id', array_keys($allocations))->lockForUpdate()->get()->keyBy('id');

            $currency = $from->currency ?? $bills->first()?->currency;
            foreach ($allocations as $billId => $amount) {
                $bill = $bills->get($billId);
                if ($bill === null || $bill->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages(['allocations' => 'A bill is not this supplier\'s.']);
                }
                if ($bill->currency !== $currency) {
                    throw ValidationException::withMessages(['allocations' => "Bill {$bill->number} is in ".($bill->currency ?? Money::currency()).'; this payment is in '.($currency ?? Money::currency()).'.']);
                }
                if ($amount > $bill->currencyOutstanding()) {
                    throw ValidationException::withMessages(['allocations' => "Bill {$bill->number} has only ".Money::toMajor($bill->currencyOutstanding(), $bill->currency).' outstanding.']);
                }
            }
            if ($currency !== null && array_sum($allocations) !== $amountMinor) {
                throw ValidationException::withMessages(['allocations' => "A payment in {$currency} is set in full against {$currency} bills."]);
            }

            $rate = $currency === null ? null : ($rate ?? $this->currencies->rate($currency, $paidOn));
            $paid = $currency === null ? $amountMinor : Currencies::toBooks($amountMinor, $currency, (string) $rate);
            $cleared = [];
            foreach ($allocations as $billId => $amount) {
                $bill = $bills->get($billId);
                $cleared[$billId] = match (true) {
                    $bill === null, $currency === null => $amount,
                    $amount === $bill->currencyOutstanding() => $bill->outstanding(),
                    default => min($bill->outstanding(), Currencies::toBooks($amount, $currency, (string) $bill->exchange_rate)),
                };
            }
            // Books' currency: what is left over stays on account.
            $debit = $currency === null ? $amountMinor : array_sum($cleared);
            $fx = $debit - $paid;
            $tagged = $currency === null ? null : ['code' => $currency, 'amount' => $amountMinor];

            $number = $this->numbers->next('supplier_payment', 'PAY');
            $lines = [
                ['account' => Account::system(Account::PAYABLE), 'debit' => $debit, 'description' => $supplier->name, 'currency' => $tagged],
                ['account' => $from, 'credit' => $paid, 'description' => $reference, 'currency' => $from->currency === null ? null : $tagged],
            ];
            if ($fx !== 0) {
                $this->chart->ensure();
                $lines[] = ['account' => Account::system(Account::FX), $fx > 0 ? 'credit' : 'debit' => abs($fx), 'description' => "Exchange difference on payment to {$supplier->name}"];
            }
            $journal = $this->ledger->post($paidOn, "Payment {$number} to {$supplier->name}", $lines, ['source_type' => 'supplier_payment', 'source_reference' => $number], $by);

            $payment = SupplierPayment::query()->create([
                'number' => $number,
                'supplier_id' => $supplier->id,
                'paid_on' => $paidOn,
                'amount_minor' => $paid,
                'account_id' => $from->id,
                'reference' => $reference,
                'memo' => $memo,
                'journal_id' => $journal->id,
                'created_by' => $by->id,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'currency_amount_minor' => $currency === null ? null : $amountMinor,
                'fx_minor' => $fx,
            ]);

            foreach ($allocations as $billId => $amount) {
                $payment->allocations()->create([
                    'bill_id' => $billId,
                    'amount_minor' => $cleared[$billId],
                    'currency_amount_minor' => $currency === null ? null : $amount,
                ]);
                $bill = $bills->get($billId);
                $bill?->forceFill([
                    'paid_minor' => $bill->paid_minor + $cleared[$billId],
                    'currency_paid_minor' => $bill->currency_paid_minor + ($currency === null ? 0 : $amount),
                ])->save();
            }

            return $payment;
        });
    }
}
