<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Budget;
use App\Models\DepreciationRun;
use App\Models\DimensionValue;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Models\JournalDraft;
use App\Models\JournalLine;
use App\Models\PaymentRequest;
use App\Models\RecurringJournal;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The two dashboards, read from the same records as every other page: the
 * ledger through Statements and Reports, documents and requests directly.
 * Nothing is stored for them. Each section is only for members who may see
 * what it is drawn from.
 */
class Dashboards
{
    /** @var array<string, array{debits: int, credits: int}>|null */
    private ?array $totals = null;

    public function __construct(
        private readonly Statements $statements,
        private readonly Reports $reports,
        private readonly Budgets $budgets,
        private readonly Balances $balances,
        private readonly JournalApprovals $approvals,
        private readonly PaymentPolicy $payments,
    ) {}

    /**
     * Balances per account, read once however many sections need them.
     *
     * @return array<string, array{debits: int, credits: int}>
     */
    public function totals(): array
    {
        return $this->totals ??= $this->balances->totals();
    }

    /**
     * @param  Closure(string): bool  $can
     * @return array<string, mixed>
     */
    public function finance(Closure $can, CarbonImmutable $today): array
    {
        $reports = $can('finance.reports.view');
        $payments = $can('finance.payments.view');
        $monthStart = $today->startOfMonth();

        return [
            'queue' => $payments ? $this->paymentQueue() : null,
            'expenses' => $payments ? $this->count(PaymentRequest::query()->whereIn('kind', [PaymentRequest::EXPENSE, PaymentRequest::ADVANCE])->whereIn('status', [PaymentRequest::PENDING_REVIEW, PaymentRequest::PENDING_APPROVAL])) : null,
            'budget' => $reports ? $this->budgetUse($today) : null,
            'cashFlow' => $reports ? array_intersect_key($this->reports->cashFlow($monthStart, $today), array_flip(['operatingMinor', 'investingMinor', 'financingMinor', 'netChangeMinor', 'openingCashMinor', 'closingCashMinor'])) : null,
            'performance' => $reports ? $this->performance($today) : null,
            'departments' => $reports ? $this->departments($monthStart, $today) : null,
        ];
    }

    /**
     * @param  Closure(string): bool  $can
     * @return array<string, mixed>
     */
    public function accounting(Closure $can, CarbonImmutable $today, bool $fixedAssets): array
    {
        $journals = $can('finance.journals.view');
        $banking = $can('finance.banking.view');
        $reports = $can('finance.reports.view');
        $payments = $can('finance.payments.view');
        $banks = $banking ? $this->bankStatus() : [];

        return [
            'journals' => $journals ? $this->pendingJournals() : null,
            'unposted' => $journals || $payments ? $this->unposted($today, $journals, $payments) : null,
            'banks' => $banking ? array_values(array_filter($banks, fn (array $bank) => ! $bank['petty'])) : null,
            'pettyCash' => $banking ? array_values(array_filter($banks, fn (array $bank) => $bank['petty'])) : null,
            'trialBalance' => $reports ? $this->trialBalance($today) : null,
            'ledger' => $journals ? $this->ledgerActivity($today->startOfMonth(), $today) : null,
            'advances' => $reports ? $this->advances() : null,
            'close' => $journals ? $this->closingTasks($today, $fixedAssets) : null,
        ];
    }

    /**
     * @return array{review: array{count: int, minor: int}, approval: array{count: int, minor: int}, disbursement: array{count: int, minor: int}}
     */
    private function paymentQueue(): array
    {
        return [
            'review' => $this->count(PaymentRequest::query()->where('status', PaymentRequest::PENDING_REVIEW)),
            'approval' => $this->count(PaymentRequest::query()->where('status', PaymentRequest::PENDING_APPROVAL)),
            'disbursement' => $this->count(PaymentRequest::query()->where('status', PaymentRequest::APPROVED)),
        ];
    }

    /**
     * @param  Builder<PaymentRequest>  $query
     * @return array{count: int, minor: int}
     */
    private function count(Builder $query): array
    {
        return ['count' => (clone $query)->count(), 'minor' => (int) (clone $query)->sum('books_minor')];
    }

    /**
     * The current year's first budget, year to date: spending against plan,
     * and the accounts closest to or over their plan.
     *
     * @return array<string, mixed>|null
     */
    private function budgetUse(CarbonImmutable $today): ?array
    {
        $budget = Budget::query()->with(['year.periods', 'costCentre'])->orderBy('name')->get()
            ->first(fn (Budget $budget) => $today->between($budget->year->starts_on, $budget->year->ends_on));
        if ($budget === null) {
            return null;
        }

        $periods = $budget->year->periods;
        $current = $periods->first(fn (FiscalPeriod $period) => $today->between($period->starts_on, $period->ends_on));
        $first = $periods->first();
        if ($current === null || $first === null) {
            return null;
        }

        $report = $this->budgets->report($budget, $first, $current);
        $rows = array_map(fn (array $row) => [
            'id' => $row['id'],
            'name' => "{$row['code']} {$row['name']}",
            'budgetMinor' => $row['budgetMinor'],
            'actualMinor' => $row['actualMinor'],
            'percent' => self::percent($row['actualMinor'], $row['budgetMinor']),
        ], $report['expenses']);
        usort($rows, fn (array $a, array $b) => ($b['percent'] ?? PHP_INT_MAX) <=> ($a['percent'] ?? PHP_INT_MAX));

        return [
            'id' => $budget->id,
            'name' => $budget->name,
            'budgetMinor' => $report['expenses_total']['budgetMinor'],
            'actualMinor' => $report['expenses_total']['actualMinor'],
            'percent' => self::percent($report['expenses_total']['actualMinor'], $report['expenses_total']['budgetMinor']),
            'accounts' => array_slice($rows, 0, 5),
        ];
    }

    /**
     * @return array{revenueMinor: int, expensesMinor: int, profitMinor: int, previousProfitMinor: int, marginPercent: float|null}
     */
    private function performance(CarbonImmutable $today): array
    {
        $month = $this->statements->profitAndLoss($today->startOfMonth(), $today);
        $before = $today->subMonthNoOverflow();
        $previous = $this->statements->profitAndLoss($before->startOfMonth(), $before->endOfMonth()->startOfDay());

        return [
            'revenueMinor' => $month['revenueMinor'],
            'expensesMinor' => $month['expensesMinor'],
            'profitMinor' => $month['profitMinor'],
            'previousProfitMinor' => $previous['profitMinor'],
            'marginPercent' => self::percent($month['profitMinor'], $month['revenueMinor']),
        ];
    }

    /**
     * Spending this month per cost centre, most first.
     *
     * @return list<array{id: string, label: string, minor: int}>
     */
    private function departments(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $split = $this->statements->profitAndLossBy(DimensionValue::COST_CENTRE, $from, $to);
        $rows = [];
        foreach ($split['columns'] as $column) {
            $minor = $split['expenseTotals'][$column['id']] ?? 0;
            if ($minor !== 0) {
                $rows[] = ['id' => $column['id'], 'label' => $column['label'], 'minor' => $minor];
            }
        }
        usort($rows, fn (array $a, array $b) => $b['minor'] <=> $a['minor']);

        return $rows;
    }

    /**
     * @return array{count: int, minor: int, oldest: list<array{id: string, number: string, memo: string, minor: int, by: string}>}
     */
    private function pendingJournals(): array
    {
        $query = JournalDraft::query()->where('status', JournalDraft::PENDING);

        return [
            'count' => (clone $query)->count(),
            'minor' => (int) (clone $query)->sum('total_minor'),
            'oldest' => array_values((clone $query)->with('submitter')->orderBy('created_at')->limit(5)->get()->map(fn (JournalDraft $draft) => [
                'id' => $draft->id,
                'number' => $draft->number,
                'memo' => $draft->memo,
                'minor' => $draft->total_minor,
                'by' => $draft->submitter->name,
            ])->all()),
        ];
    }

    /**
     * What has happened or fallen due but is not in the ledger yet.
     *
     * @return array{payments: array{count: int, minor: int}|null, recurringDue: int|null, recurringHeld: int|null}
     */
    private function unposted(CarbonImmutable $today, bool $journals, bool $payments): array
    {
        $due = $journals
            ? RecurringJournal::query()->where('active', true)->whereNotNull('next_on')->whereDate('next_on', '<=', $today)->get()
            : collect();
        $banks = $this->payments->bankIds();
        $held = $due->reject(fn (RecurringJournal $template) => $this->approvals->recurringCleared($template, $banks))->count();

        return [
            'payments' => $payments ? $this->count(PaymentRequest::query()->where('status', PaymentRequest::APPROVED)) : null,
            'recurringDue' => $journals ? $due->count() - $held : null,
            'recurringHeld' => $journals ? $held : null,
        ];
    }

    /**
     * Each bank and cash account against its latest statement.
     *
     * @return list<array{id: string, name: string, petty: bool, balanceMinor: int, statementEndsOn: string|null, reconciled: bool, unmatched: int}>
     */
    private function bankStatus(): array
    {
        $totals = $this->totals();
        $banks = Account::query()->banks()->where('active', true)->orderBy('code')->get();

        // Each account's latest statement, and what is unmatched on those, in one go each.
        $latestStatements = BankStatement::query()->whereIn('account_id', $banks->modelKeys())->orderByDesc('ends_on')->get()->unique('account_id')->keyBy('account_id');
        $unmatched = BankStatementLine::query()->whereIn('bank_statement_id', $latestStatements->modelKeys())->whereNull('journal_line_id')
            ->groupBy('bank_statement_id')->selectRaw('bank_statement_id, COUNT(*) AS open')->toBase()->pluck('open', 'bank_statement_id');

        $rows = [];
        foreach ($banks as $bank) {
            $latest = $latestStatements->get($bank->id);
            $rows[] = [
                'id' => $bank->id,
                'name' => $bank->name,
                'petty' => $bank->bank_kind === BankKind::PettyCash,
                'balanceMinor' => AccountType::Asset->balance($totals[$bank->id]['debits'] ?? 0, $totals[$bank->id]['credits'] ?? 0),
                'statementEndsOn' => $latest?->ends_on->toDateString(),
                'reconciled' => $latest?->reconciled() ?? false,
                'unmatched' => $latest === null ? 0 : (int) ($unmatched[$latest->id] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @return array{debitMinor: int, creditMinor: int, balanced: bool}
     */
    private function trialBalance(CarbonImmutable $today): array
    {
        $balance = $this->statements->trialBalance($today);

        return [
            'debitMinor' => $balance['debitMinor'],
            'creditMinor' => $balance['creditMinor'],
            'balanced' => $balance['debitMinor'] === $balance['creditMinor'],
        ];
    }

    /**
     * @return array{journals: int, debitMinor: int}
     */
    private function ledgerActivity(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'journals' => Journal::query()->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)->count(),
            'debitMinor' => (int) JournalLine::query()
                ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                ->whereDate('journals.entry_date', '>=', $from)
                ->whereDate('journals.entry_date', '<=', $to)
                ->sum('journal_lines.debit_minor'),
        ];
    }

    /**
     * What staff still owe, from the ledger: money paid to them ahead less
     * whatever has been settled against the account.
     *
     * @return array{outstandingMinor: int, accountId: string|null}
     */
    private function advances(): array
    {
        $account = Account::query()->where('system_key', Account::EMPLOYEE_ADVANCES)->first();
        if ($account === null) {
            return ['outstandingMinor' => 0, 'accountId' => null];
        }

        $totals = $this->totals()[$account->id] ?? ['debits' => 0, 'credits' => 0];

        return ['outstandingMinor' => AccountType::Asset->balance($totals['debits'], $totals['credits']), 'accountId' => $account->id];
    }

    /**
     * What stands between the books and closing the earliest open month that
     * has ended. Null when no such month exists.
     *
     * @return array{month: string, periodId: string, tasks: list<array{key: string, label: string, open: int}>}|null
     */
    private function closingTasks(CarbonImmutable $today, bool $fixedAssets): ?array
    {
        $period = FiscalPeriod::query()->whereNull('locked_at')->whereDate('ends_on', '<', $today)->orderBy('starts_on')->first();
        if ($period === null) {
            return null;
        }

        $end = $period->ends_on;
        $tasks = [
            ['key' => 'journals', 'label' => 'Journals waiting for approval', 'open' => JournalDraft::query()->where('status', JournalDraft::PENDING)->whereDate('entry_date', '<=', $end)->count()],
            ['key' => 'payments', 'label' => 'Payments due but not disbursed', 'open' => PaymentRequest::query()->whereIn('status', PaymentRequest::OPEN)->whereDate('pay_on', '<=', $end)->count()],
            ['key' => 'recurring', 'label' => 'Recurring journals not yet posted', 'open' => RecurringJournal::query()->where('active', true)->whereNotNull('next_on')->whereDate('next_on', '<=', $end)->count()],
            ['key' => 'reconciliation', 'label' => 'Bank and cash accounts not reconciled to the month end', 'open' => $this->unreconciledAt($end)],
        ];
        if ($fixedAssets) {
            $tasks[] = ['key' => 'depreciation', 'label' => 'Depreciation not run', 'open' => DepreciationRun::query()->whereDate('month', '>=', $period->starts_on)->whereDate('month', '<=', $end)->exists() ? 0 : 1];
        }

        return ['month' => $period->starts_on->format('F Y'), 'periodId' => $period->id, 'tasks' => $tasks];
    }

    /**
     * Active accounts with movements by $end but no reconciled statement
     * reaching it.
     */
    private function unreconciledAt(CarbonImmutable $end): int
    {
        $banks = Account::query()->banks()->where('active', true)->pluck('id')->all();
        $moved = JournalLine::query()->whereIn('account_id', $banks)
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereDate('journals.entry_date', '<=', $end)
            ->distinct()->pluck('journal_lines.account_id')->all();
        $reconciled = BankStatement::query()->whereIn('account_id', $moved)->whereNotNull('reconciled_at')->whereDate('ends_on', '>=', $end)
            ->distinct()->pluck('account_id')->all();

        return count(array_diff($moved, $reconciled));
    }

    private static function percent(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part * 100 / $whole, 1);
    }
}
