<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Revaluation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unrealised exchange gains and losses: at a day's rates, what the books
 * carry for money and open documents in other currencies is brought to
 * their value that day, against the exchange gains and losses account. The
 * journal is dated that day and reversed the next, so later payments still
 * clear documents at their own rates (the realised difference is posted
 * then, see Receivables and Payables).
 *
 * Revalued: bank and cash accounts held in another currency; invoices and
 * bills in another currency still open on the day.
 */
class Revaluations
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Currencies $currencies,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * What a revaluation on the day would post, without posting it: per
     * account or document, the amount in its currency, what the books carry,
     * its value at the day's rate and the difference.
     *
     * @return list<array{kind: string, label: string, currency: string, currencyMinor: int, carriedMinor: int, valueMinor: int, differenceMinor: int, rate: string}>
     */
    public function preview(CarbonImmutable $asOf): array
    {
        return array_map(function (array $row): array {
            unset($row['account']);

            return $row;
        }, $this->rows($asOf));
    }

    /**
     * @return list<array{kind: string, label: string, currency: string, currencyMinor: int, carriedMinor: int, valueMinor: int, differenceMinor: int, rate: string, account: Account|null}>
     */
    private function rows(CarbonImmutable $asOf): array
    {
        $rows = [];
        $rates = [];
        $rate = function (string $currency) use ($asOf, &$rates): string {
            return $rates[$currency] ??= $this->currencies->rate($currency, $asOf, 'as_of');
        };

        foreach (Account::query()->banks()->whereNotNull('currency')->orderBy('code')->get() as $bank) {
            $totals = JournalLine::query()
                ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                ->where('journal_lines.account_id', $bank->id)
                ->whereDate('journals.entry_date', '<=', $asOf)
                ->selectRaw('COALESCE(SUM(journal_lines.currency_minor), 0) AS foreign_balance, COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) AS carried')
                ->toBase()
                ->first();
            $foreign = (int) ($totals->foreign_balance ?? 0);
            $carried = (int) ($totals->carried ?? 0);
            if ($foreign === 0 && $carried === 0) {
                continue;
            }
            $currency = (string) $bank->currency;
            $value = Currencies::toBooks($foreign, $currency, $rate($currency));
            $rows[] = ['kind' => 'bank', 'label' => $bank->label(), 'currency' => $currency, 'currencyMinor' => $foreign, 'carriedMinor' => $carried, 'valueMinor' => $value, 'differenceMinor' => $value - $carried, 'rate' => $rate($currency), 'account' => $bank];
        }

        $invoices = Invoice::query()->whereNotNull('currency')->whereNull('voided_at')->whereDate('issued_on', '<=', $asOf)->orderBy('number')->get();
        foreach ($invoices as $invoice) {
            $paid = CustomerPayment::query()->where('invoice_id', $invoice->id)->whereDate('received_on', '<=', $asOf)
                ->selectRaw('COALESCE(SUM(currency_amount_minor), 0) AS foreign_paid, COALESCE(SUM(amount_minor - fx_minor), 0) AS cleared')
                ->toBase()->first();
            $foreign = (int) $invoice->currency_total_minor - (int) ($paid->foreign_paid ?? 0);
            if ($foreign <= 0) {
                continue;
            }
            $carried = $invoice->total_minor - (int) ($paid->cleared ?? 0);
            $currency = (string) $invoice->currency;
            $value = Currencies::toBooks($foreign, $currency, $rate($currency));
            $rows[] = ['kind' => 'invoice', 'label' => "Invoice {$invoice->number}", 'currency' => $currency, 'currencyMinor' => $foreign, 'carriedMinor' => $carried, 'valueMinor' => $value, 'differenceMinor' => $value - $carried, 'rate' => $rate($currency), 'account' => null];
        }

        $bills = Bill::query()->whereNotNull('currency')->whereDate('bill_date', '<=', $asOf)->orderBy('number')->get();
        foreach ($bills as $bill) {
            $paid = BillPayment::query()
                ->join('supplier_payments', 'supplier_payments.id', '=', 'bill_payments.supplier_payment_id')
                ->where('bill_payments.bill_id', $bill->id)
                ->whereDate('supplier_payments.paid_on', '<=', $asOf)
                ->selectRaw('COALESCE(SUM(bill_payments.currency_amount_minor), 0) AS foreign_paid, COALESCE(SUM(bill_payments.amount_minor), 0) AS cleared')
                ->toBase()->first();
            $foreign = (int) $bill->currency_total_minor - (int) ($paid->foreign_paid ?? 0);
            if ($foreign <= 0) {
                continue;
            }
            $carried = $bill->total_minor - (int) ($paid->cleared ?? 0);
            $currency = (string) $bill->currency;
            $value = Currencies::toBooks($foreign, $currency, $rate($currency));
            $rows[] = ['kind' => 'bill', 'label' => "Bill {$bill->number}", 'currency' => $currency, 'currencyMinor' => $foreign, 'carriedMinor' => $carried, 'valueMinor' => $value, 'differenceMinor' => $value - $carried, 'rate' => $rate($currency), 'account' => null];
        }

        return $rows;
    }

    /**
     * Posts the revaluation on the day and its reversal the next.
     *
     * @throws ValidationException when the day is revalued already, a rate is missing, or a day is locked
     */
    public function run(CarbonImmutable $asOf, User $by): Revaluation
    {
        if ($asOf->greaterThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['as_of' => 'Revalue on today or a day before.']);
        }

        return DB::transaction(function () use ($asOf, $by) {
            if (Revaluation::query()->whereDate('as_of', $asOf)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['as_of' => "Balances are already revalued at {$asOf->format('j M Y')}."]);
            }

            $lines = $this->lines($asOf);
            $journal = null;
            $reversal = null;
            if ($lines !== []) {
                $journal = $this->ledger->post($asOf, "Revaluation at {$asOf->format('j M Y')} rates", $lines, ['source_type' => Journal::FX_REVALUATION, 'source_reference' => $asOf->toDateString()], $by);
                $reversal = $this->ledger->reverse($journal, $asOf->addDay(), "Reversal of the revaluation at {$asOf->format('j M Y')}", $by, Journal::FX_REVALUATION_REVERSAL);
            }

            return Revaluation::query()->create([
                'as_of' => $asOf,
                'journal_id' => $journal?->id,
                'reversal_journal_id' => $reversal?->id,
                'created_by' => $by->id,
            ]);
        });
    }

    /**
     * @return list<array{account: Account, debit?: int, credit?: int, description?: string, currency?: array{code: string, amount: int}}>
     */
    private function lines(CarbonImmutable $asOf): array
    {
        $rows = $this->rows($asOf);
        $lines = [];
        $gain = 0;
        $receivable = 0;
        $payable = 0;

        foreach ($rows as $row) {
            $difference = $row['differenceMinor'];
            if ($difference === 0) {
                continue;
            }
            match ($row['kind']) {
                'bank' => $lines[] = [
                    'account' => $row['account'],
                    $difference > 0 ? 'debit' : 'credit' => abs($difference),
                    'description' => "{$row['currency']} at {$row['rate']}",
                    // The money in the currency does not change.
                    'currency' => ['code' => $row['currency'], 'amount' => 0],
                ],
                'invoice' => $receivable += $difference,
                default => $payable += $difference,
            };
            // More assets is a gain; a larger liability is a loss.
            $gain += $row['kind'] === 'bill' ? -$difference : $difference;
        }

        if ($receivable !== 0) {
            $lines[] = ['account' => Account::system(Account::RECEIVABLE), $receivable > 0 ? 'debit' : 'credit' => abs($receivable), 'description' => 'Open invoices in other currencies'];
        }
        if ($payable !== 0) {
            $lines[] = ['account' => Account::system(Account::PAYABLE), $payable > 0 ? 'credit' : 'debit' => abs($payable), 'description' => 'Open bills in other currencies'];
        }
        if ($gain !== 0) {
            $this->chart->ensure();
            $lines[] = ['account' => Account::system(Account::FX), $gain > 0 ? 'credit' : 'debit' => abs($gain), 'description' => 'Unrealised exchange '.($gain > 0 ? 'gain' : 'loss')];
        }

        return $lines;
    }
}
