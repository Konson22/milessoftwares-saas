<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\Bill;
use App\Models\Supplier;
use App\Models\SupplierPayment;
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
    ) {}

    /**
     * Dr each line's account (+ tax payable for tax) / Cr accounts payable.
     *
     * @param  list<array{account: Account, description: string, amount_minor: int}>  $lines
     */
    public function recordBill(Supplier $supplier, CarbonImmutable $billDate, ?CarbonImmutable $dueOn, ?string $supplierReference, array $lines, int $taxMinor, ?string $memo, User $by): Bill
    {
        return DB::transaction(function () use ($supplier, $billDate, $dueOn, $supplierReference, $lines, $taxMinor, $memo, $by) {
            $number = $this->numbers->next('bill', 'BILL');
            $total = array_sum(array_column($lines, 'amount_minor')) + $taxMinor;

            $journalLines = array_map(fn (array $line) => [
                'account' => $line['account'],
                'debit' => $line['amount_minor'],
                'description' => $line['description'],
            ], $lines);
            if ($taxMinor > 0) {
                $journalLines[] = ['account' => Account::system(Account::TAX), 'debit' => $taxMinor];
            }
            $journalLines[] = ['account' => Account::system(Account::PAYABLE), 'credit' => $total, 'description' => $supplier->name];

            $journal = $this->ledger->post($billDate, "Bill {$number} from {$supplier->name}", $journalLines, [
                'source_type' => 'bill',
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
                'tax_minor' => $taxMinor,
                'lines' => array_map(fn (array $line) => [
                    'description' => $line['description'],
                    'quantity' => null,
                    'amount_minor' => $line['amount_minor'],
                    'account' => $line['account']->code,
                    'cost_minor' => null,
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
     * @param  array<string, int>  $allocations  bill id => amount
     */
    public function pay(Supplier $supplier, Account $from, int $amountMinor, CarbonImmutable $paidOn, array $allocations, ?string $reference, ?string $memo, User $by): SupplierPayment
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

        return DB::transaction(function () use ($supplier, $from, $amountMinor, $paidOn, $allocations, $reference, $memo, $by) {
            $bills = Bill::query()->whereIn('id', array_keys($allocations))->lockForUpdate()->get()->keyBy('id');

            foreach ($allocations as $billId => $amount) {
                $bill = $bills->get($billId);
                if ($bill === null || $bill->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages(['allocations' => 'A bill is not this supplier\'s.']);
                }
                if ($amount > $bill->outstanding()) {
                    throw ValidationException::withMessages(['allocations' => "Bill {$bill->number} has only {$bill->outstanding()} outstanding."]);
                }
            }

            $number = $this->numbers->next('supplier_payment', 'PAY');
            $journal = $this->ledger->post($paidOn, "Payment {$number} to {$supplier->name}", [
                ['account' => Account::system(Account::PAYABLE), 'debit' => $amountMinor, 'description' => $supplier->name],
                ['account' => $from, 'credit' => $amountMinor, 'description' => $reference],
            ], ['source_type' => 'supplier_payment', 'source_reference' => $number], $by);

            $payment = SupplierPayment::query()->create([
                'number' => $number,
                'supplier_id' => $supplier->id,
                'paid_on' => $paidOn,
                'amount_minor' => $amountMinor,
                'account_id' => $from->id,
                'reference' => $reference,
                'memo' => $memo,
                'journal_id' => $journal->id,
                'created_by' => $by->id,
            ]);

            foreach ($allocations as $billId => $amount) {
                $payment->allocations()->create(['bill_id' => $billId, 'amount_minor' => $amount]);
                $bill = $bills->get($billId);
                $bill?->forceFill(['paid_minor' => $bill->paid_minor + $amount])->save();
            }

            return $payment;
        });
    }
}
