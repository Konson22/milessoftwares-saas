<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The accountant's reports beyond the three statements: general ledger
 * detail, aged receivables and payables, and the cash flow statement.
 */
class Reports
{
    /** Lines shown in the general ledger at most; totals always cover every line. */
    public const LEDGER_LINES = 5000;

    /** Ageing buckets: days past due, up to and including. */
    public const BUCKETS = ['current' => 0, 'days30' => 30, 'days60' => 60, 'days90' => 90, 'older' => PHP_INT_MAX];

    public function __construct(private readonly Balances $balances) {}

    /**
     * Every account with a balance or a movement: its opening balance, each
     * line in the period with the running balance (in the account's normal
     * direction), and its closing balance.
     *
     * @return array{accounts: list<array{id: string, code: string, name: string, type: string, openingMinor: int, debitMinor: int, creditMinor: int, closingMinor: int, lines: list<array{id: string, journalId: string, number: string, date: string, memo: string, description: string|null, source: string, debitMinor: int, creditMinor: int, balanceMinor: int}>}>, debitMinor: int, creditMinor: int, truncated: bool}
     */
    public function generalLedger(CarbonImmutable $from, CarbonImmutable $to, ?string $accountId = null): array
    {
        $before = $this->balances->totals(to: $from->subDay());
        $during = $this->balances->totals($from, $to);

        $lines = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereDate('journals.entry_date', '>=', $from)
            ->whereDate('journals.entry_date', '<=', $to)
            ->when($accountId, fn ($query) => $query->where('journal_lines.account_id', $accountId))
            ->orderBy('accounts.code')
            ->orderBy('journals.entry_date')
            ->orderBy('journals.number')
            ->orderBy('journal_lines.position')
            ->limit(self::LEDGER_LINES + 1)
            ->select('journal_lines.*', 'journals.number', 'journals.entry_date', 'journals.memo', 'journals.source_app')
            ->get();
        $truncated = $lines->count() > self::LEDGER_LINES;
        $byAccount = $lines->take(self::LEDGER_LINES)->groupBy('account_id');

        $accounts = [];
        $debits = 0;
        $credits = 0;
        foreach ($this->accounts() as $account) {
            if ($accountId !== null && $account->id !== $accountId) {
                continue;
            }

            $opening = $account->type->balance($before[$account->id]['debits'] ?? 0, $before[$account->id]['credits'] ?? 0);
            $debit = $during[$account->id]['debits'] ?? 0;
            $credit = $during[$account->id]['credits'] ?? 0;
            if ($opening === 0 && $debit === 0 && $credit === 0) {
                continue;
            }

            $balance = $opening;
            $rows = [];
            foreach ($byAccount->get($account->id, []) as $line) {
                $balance += $account->type->balance($line->debit_minor, $line->credit_minor);
                $rows[] = [
                    'id' => $line->id,
                    'journalId' => $line->journal_id,
                    'number' => (string) $line->getAttribute('number'),
                    'date' => substr((string) $line->getAttribute('entry_date'), 0, 10),
                    'memo' => (string) $line->getAttribute('memo'),
                    'description' => $line->description,
                    'source' => (string) $line->getAttribute('source_app'),
                    'debitMinor' => $line->debit_minor,
                    'creditMinor' => $line->credit_minor,
                    'balanceMinor' => $balance,
                ];
            }

            $accounts[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'openingMinor' => $opening,
                'debitMinor' => $debit,
                'creditMinor' => $credit,
                'closingMinor' => $opening + $account->type->balance($debit, $credit),
                'lines' => $rows,
            ];
            $debits += $debit;
            $credits += $credit;
        }

        return ['accounts' => $accounts, 'debitMinor' => $debits, 'creditMinor' => $credits, 'truncated' => $truncated];
    }

    /**
     * What customers owe today, by how long it is past due, against the
     * receivable account (opening balances and payments waiting for their
     * invoice are in the account but on no open invoice).
     *
     * @return array{asOf: string, parties: list<array<string, int|string>>, documents: list<array<string, int|string|null>>, totals: array<string, int>, accountMinor: int, differenceMinor: int}
     */
    public function agedReceivables(CarbonImmutable $today): array
    {
        $invoices = Invoice::query()
            ->whereNull('voided_at')
            ->whereRaw('total_minor - credited_minor - paid_minor > 0')
            ->orderBy('issued_on')
            ->get();

        return $this->aged(
            $today,
            $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'party' => $invoice->customer_name ?? 'No customer name',
                'date' => $invoice->issued_on,
                'dueOn' => $invoice->due_on,
                'totalMinor' => $invoice->total_minor,
                'outstandingMinor' => $invoice->outstanding(),
            ])->values()->all(),
            Account::RECEIVABLE,
        );
    }

    /**
     * What is owed to suppliers today, by how long it is past due, against
     * the payable account.
     *
     * @return array{asOf: string, parties: list<array<string, int|string>>, documents: list<array<string, int|string|null>>, totals: array<string, int>, accountMinor: int, differenceMinor: int}
     */
    public function agedPayables(CarbonImmutable $today): array
    {
        $bills = Bill::query()
            ->with('supplier')
            ->whereColumn('paid_minor', '<', 'total_minor')
            ->orderBy('bill_date')
            ->get();

        return $this->aged(
            $today,
            $bills->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'number' => $bill->number,
                'party' => $bill->supplier->name,
                'date' => $bill->bill_date,
                'dueOn' => $bill->due_on,
                'totalMinor' => $bill->total_minor,
                'outstandingMinor' => $bill->outstanding(),
            ])->values()->all(),
            Account::PAYABLE,
        );
    }

    /**
     * The cash flow statement, indirect method: the period's profit, turned
     * into cash by every other balance sheet movement, by activity. Year-end
     * closing journals are left out (they move no cash). Ends exactly at the
     * change in bank and cash accounts.
     *
     * @return array{profitMinor: int, operating: list<array{id: string, code: string, name: string, amountMinor: int}>, investing: list<array{id: string, code: string, name: string, amountMinor: int}>, financing: list<array{id: string, code: string, name: string, amountMinor: int}>, operatingMinor: int, investingMinor: int, financingMinor: int, netChangeMinor: int, openingCashMinor: int, closingCashMinor: int}
     */
    public function cashFlow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $movements = $this->balances->totals($from, $to, withClosing: false);
        $before = $this->balances->totals(to: $from->subDay());
        $after = $this->balances->totals(to: $to);

        $profit = 0;
        $sections = ['operating' => [], 'investing' => [], 'financing' => []];
        $openingCash = 0;
        $closingCash = 0;

        foreach ($this->accounts() as $account) {
            $debits = $movements[$account->id]['debits'] ?? 0;
            $credits = $movements[$account->id]['credits'] ?? 0;

            if ($account->isBank()) {
                $openingCash += ($before[$account->id]['debits'] ?? 0) - ($before[$account->id]['credits'] ?? 0);
                $closingCash += ($after[$account->id]['debits'] ?? 0) - ($after[$account->id]['credits'] ?? 0);

                continue;
            }
            if (in_array($account->type, [AccountType::Revenue, AccountType::Expense], true)) {
                $profit += $credits - $debits;

                continue;
            }

            // Less of an asset or more of a liability is cash in.
            $cash = $credits - $debits;
            if ($cash !== 0) {
                $activity = $account->cashFlowActivity() ?? CashFlowActivity::Operating;
                $sections[$activity->value][] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'amountMinor' => $cash];
            }
        }

        $operating = $profit + array_sum(array_column($sections['operating'], 'amountMinor'));
        $investing = array_sum(array_column($sections['investing'], 'amountMinor'));
        $financing = array_sum(array_column($sections['financing'], 'amountMinor'));

        return [
            'profitMinor' => $profit,
            'operating' => $sections['operating'],
            'investing' => $sections['investing'],
            'financing' => $sections['financing'],
            'operatingMinor' => $operating,
            'investingMinor' => $investing,
            'financingMinor' => $financing,
            'netChangeMinor' => $operating + $investing + $financing,
            'openingCashMinor' => $openingCash,
            'closingCashMinor' => $closingCash,
        ];
    }

    /**
     * @param  array<int, array{id: string, number: string, party: string, date: CarbonImmutable, dueOn: CarbonImmutable|null, totalMinor: int, outstandingMinor: int}>  $documents
     * @return array{asOf: string, parties: list<array<string, int|string>>, documents: list<array<string, int|string|null>>, totals: array<string, int>, accountMinor: int, differenceMinor: int}
     */
    private function aged(CarbonImmutable $today, array $documents, string $accountKey): array
    {
        /** @var array<string, int> $empty */
        $empty = array_fill_keys([...array_map(fn (string $bucket) => "{$bucket}Minor", array_keys(self::BUCKETS)), 'totalMinor'], 0);
        /** @var array<string, array<string, int>> $byParty */
        $byParty = [];
        $rows = [];
        $totals = $empty;

        foreach ($documents as $document) {
            $due = $document['dueOn'] ?? $document['date'];
            $days = max(0, (int) $due->diffInDays($today, false));
            $bucket = $this->bucket($days);
            $amount = $document['outstandingMinor'];

            $byParty[$document['party']] ??= $empty;
            $byParty[$document['party']]["{$bucket}Minor"] += $amount;
            $byParty[$document['party']]['totalMinor'] += $amount;
            $totals["{$bucket}Minor"] += $amount;
            $totals['totalMinor'] += $amount;

            $rows[] = [
                'id' => $document['id'],
                'number' => $document['number'],
                'party' => $document['party'],
                'date' => $document['date']->toDateString(),
                'dueOn' => $document['dueOn']?->toDateString(),
                'daysOverdue' => $days,
                'bucket' => $bucket,
                'totalMinor' => $document['totalMinor'],
                'outstandingMinor' => $amount,
            ];
        }

        ksort($byParty, SORT_NATURAL | SORT_FLAG_CASE);
        $parties = [];
        foreach ($byParty as $party => $amounts) {
            $parties[] = ['party' => (string) $party] + $amounts;
        }

        $account = Account::system($accountKey);
        $all = $this->balances->totals();
        $balance = $account->type->balance($all[$account->id]['debits'] ?? 0, $all[$account->id]['credits'] ?? 0);

        return [
            'asOf' => $today->toDateString(),
            'parties' => $parties,
            'documents' => $rows,
            'totals' => $totals,
            'accountMinor' => $balance,
            'differenceMinor' => $balance - $totals['totalMinor'],
        ];
    }

    /**
     * The ageing bucket for a number of days past due.
     */
    private function bucket(int $days): string
    {
        foreach (self::BUCKETS as $bucket => $limit) {
            if ($days <= $limit) {
                return $bucket;
            }
        }

        return 'older';
    }

    /**
     * @return Collection<int, Account>
     */
    private function accounts(): Collection
    {
        return Account::query()->orderBy('code')->get();
    }
}
