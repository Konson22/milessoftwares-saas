<?php

namespace App\Http\Controllers;

use App\Finance\Balances;
use App\Finance\Ratios;
use App\Finance\Reports;
use App\Finance\Statements;
use App\Models\Account;
use App\Models\DimensionValue;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Financial statements and the accountant's reports. Each report also
 * downloads its tables as CSV (?export=<table>).
 */
class ReportController extends Controller
{
    public function trialBalance(Request $request, Statements $statements): Response|StreamedResponse
    {
        $asOf = $this->asOf($request);
        $dimensions = $this->dimensionFilter($request);
        $report = $statements->trialBalance($asOf, $dimensions);

        if ($this->export($request, ['accounts'])) {
            return CsvExport::download("trial-balance-{$asOf->toDateString()}", [
                'Code' => 'code', 'Account' => 'name', 'Type' => 'type', 'Debit' => 'debitMinor', 'Credit' => 'creditMinor',
            ], $report['rows']);
        }

        return Inertia::render('reports/trial-balance', [
            'asOf' => $asOf->toDateString(),
            ...$this->dimensionProps($dimensions),
        ] + $report);
    }

    public function profitAndLoss(Request $request, Statements $statements): Response|StreamedResponse
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->startOfYear());
        $compare = $this->compare($request, ['previous_period', 'previous_year']);
        $dimensions = $this->dimensionFilter($request);
        $report = $statements->profitAndLoss($from, $to, $dimensions);
        $previous = null;

        if ($compare !== null) {
            $previous = $compare === 'previous_year'
                ? [$from->subYearNoOverflow(), $to->subYearNoOverflow()]
                : $this->previousPeriod($from, $to);
            $report = Statements::compared($report, $statements->profitAndLoss($previous[0], $previous[1], $dimensions), ['revenue', 'expenses']);
        }

        if ($this->export($request, ['statement'])) {
            return CsvExport::download("profit-and-loss-{$from->toDateString()}-{$to->toDateString()}", $this->statementColumns($compare !== null), [
                ...$this->sectionRows('Revenue', $report['revenue']),
                ...$this->sectionRows('Expenses', $report['expenses']),
            ]);
        }

        return Inertia::render('reports/profit-and-loss', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'compareWith' => $compare,
            ...$this->dimensionProps($dimensions),
            'compareLabel' => $previous === null ? null : "{$previous[0]->toDateString()} to {$previous[1]->toDateString()}",
        ] + $report);
    }

    public function profitAndLossByDimension(Request $request, Statements $statements): Response|StreamedResponse
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->startOfYear());
        $kind = $request->validate(['by' => ['nullable', Rule::in(DimensionValue::KINDS)]])['by'] ?? DimensionValue::COST_CENTRE;
        $report = $statements->profitAndLossBy($kind, $from, $to);

        if ($this->export($request, ['statement'])) {
            $columns = ['Section' => 'section', 'Code' => 'code', 'Account' => 'name'];
            foreach ($report['columns'] as $column) {
                $columns[$column['label']] = "{$column['id']}Minor";
            }
            $columns['Total'] = 'amountMinor';
            $flatten = fn (string $section, array $rows) => array_map(fn (array $row) => [
                'section' => $section, 'code' => $row['code'], 'name' => $row['name'], 'amountMinor' => $row['amountMinor'],
            ] + array_combine(
                array_map(fn (array $column) => "{$column['id']}Minor", $report['columns']),
                array_map(fn (array $column) => $row['amounts'][$column['id']] ?? 0, $report['columns']),
            ), $rows);

            return CsvExport::download("profit-and-loss-by-{$kind}-{$from->toDateString()}-{$to->toDateString()}", $columns, [
                ...$flatten('Revenue', $report['revenue']),
                ...$flatten('Expenses', $report['expenses']),
            ]);
        }

        return Inertia::render('reports/profit-and-loss-by-dimension', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'by' => $kind,
        ] + $report);
    }

    public function balanceSheet(Request $request, Statements $statements): Response|StreamedResponse
    {
        $asOf = $this->asOf($request);
        $compare = $this->compare($request, ['previous_month', 'previous_year']);
        $report = $statements->balanceSheet($asOf);
        $previous = null;

        if ($compare !== null) {
            $previous = $compare === 'previous_year' ? $asOf->subYearNoOverflow() : $asOf->startOfMonth()->subDay();
            $report = Statements::compared($report, $statements->balanceSheet($previous), ['assets', 'liabilities', 'equity']);
        }

        if ($this->export($request, ['statement'])) {
            return CsvExport::download("balance-sheet-{$asOf->toDateString()}", $this->statementColumns($compare !== null), [
                ...$this->sectionRows('Assets', $report['assets']),
                ...$this->sectionRows('Liabilities', $report['liabilities']),
                ...$this->sectionRows('Equity', $report['equity']),
                ['section' => 'Equity', 'code' => '', 'name' => 'Current earnings', 'amountMinor' => $report['earningsMinor'], 'compareMinor' => $report['compare']['earningsMinor'] ?? null],
            ]);
        }

        return Inertia::render('reports/balance-sheet', [
            'asOf' => $asOf->toDateString(),
            'compareWith' => $compare,
            'compareLabel' => $previous?->toDateString(),
        ] + $report);
    }

    public function cashFlow(Request $request, Reports $reports): Response|StreamedResponse
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->startOfYear());
        $report = $reports->cashFlow($from, $to);

        if ($this->export($request, ['statement'])) {
            return CsvExport::download("cash-flow-{$from->toDateString()}-{$to->toDateString()}", $this->statementColumns(false), [
                ['section' => 'Operating activities', 'code' => '', 'name' => 'Net profit', 'amountMinor' => $report['profitMinor']],
                ...$this->sectionRows('Operating activities', $report['operating']),
                ...$this->sectionRows('Investing activities', $report['investing']),
                ...$this->sectionRows('Financing activities', $report['financing']),
                ['section' => '', 'code' => '', 'name' => 'Net change in cash', 'amountMinor' => $report['netChangeMinor']],
                ['section' => '', 'code' => '', 'name' => 'Cash at the start', 'amountMinor' => $report['openingCashMinor']],
                ['section' => '', 'code' => '', 'name' => 'Cash at the end', 'amountMinor' => $report['closingCashMinor']],
            ]);
        }

        return Inertia::render('reports/cash-flow', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ] + $report);
    }

    /**
     * Ratios for the period, next to the same ratios for the period before.
     */
    public function ratios(Request $request, Ratios $ratios): Response|StreamedResponse
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->startOfYear());
        $previous = $this->previousPeriod($from, $to);
        $report = $ratios->for($from, $to);
        $compare = $ratios->for($previous[0], $previous[1]);

        if ($this->export($request, ['ratios'])) {
            $rows = [];
            foreach ($report['ratios'] as $key => $value) {
                $rows[] = ['ratio' => Ratios::LABELS[$key] ?? $key, 'value' => $value, 'previous' => $compare['ratios'][$key]];
            }

            return CsvExport::download("ratios-{$from->toDateString()}-{$to->toDateString()}", ['Ratio' => 'ratio', $report['to'] => 'value', $compare['to'] => 'previous'], $rows);
        }

        return Inertia::render('reports/ratios', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'report' => $report,
            'compare' => $compare,
        ]);
    }

    public function generalLedger(Request $request, Reports $reports): Response|StreamedResponse
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->startOfMonth());
        $accountId = $request->validate(['account' => ['nullable', 'uuid', $this->ours('accounts')]])['account'] ?? null;
        $report = $reports->generalLedger($from, $to, $accountId);

        if ($this->export($request, ['lines'])) {
            $rows = [];
            foreach ($report['accounts'] as $account) {
                $rows[] = ['code' => $account['code'], 'account' => $account['name'], 'date' => $from->toDateString(), 'memo' => 'Opening balance', 'balanceMinor' => $account['openingMinor']];
                foreach ($account['lines'] as $line) {
                    $rows[] = ['code' => $account['code'], 'account' => $account['name']] + $line;
                }
                $rows[] = ['code' => $account['code'], 'account' => $account['name'], 'date' => $to->toDateString(), 'memo' => 'Closing balance', 'debitMinor' => $account['debitMinor'], 'creditMinor' => $account['creditMinor'], 'balanceMinor' => $account['closingMinor']];
            }

            return CsvExport::download("general-ledger-{$from->toDateString()}-{$to->toDateString()}", [
                'Code' => 'code', 'Account' => 'account', 'Date' => 'date', 'Journal' => 'number', 'Memo' => 'memo',
                'Line' => 'description', 'From' => 'source', 'Debit' => 'debitMinor', 'Credit' => 'creditMinor', 'Balance' => 'balanceMinor',
            ], $rows);
        }

        return Inertia::render('reports/general-ledger', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'account' => $accountId,
            'accountOptions' => Account::query()->orderBy('code')->get()->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()])->values(),
            'limit' => Reports::LEDGER_LINES,
        ] + $report);
    }

    public function agedReceivables(Request $request, Reports $reports): Response|StreamedResponse
    {
        return $this->aged($request, 'receivables', $reports->agedReceivables(CarbonImmutable::today()));
    }

    public function agedPayables(Request $request, Reports $reports): Response|StreamedResponse
    {
        return $this->aged($request, 'payables', $reports->agedPayables(CarbonImmutable::today()));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function aged(Request $request, string $kind, array $report): Response|StreamedResponse
    {
        $party = $kind === 'receivables' ? 'Customer' : 'Supplier';
        $buckets = ['Current' => 'currentMinor', '1–30 days' => 'days30Minor', '31–60 days' => 'days60Minor', '61–90 days' => 'days90Minor', 'Over 90 days' => 'olderMinor', 'Total' => 'totalMinor'];

        $export = $this->export($request, ['parties', 'documents']);
        if ($export === 'parties') {
            return CsvExport::download("aged-{$kind}-{$report['asOf']}", [$party => 'party'] + $buckets, $report['parties']);
        }
        if ($export === 'documents') {
            return CsvExport::download("aged-{$kind}-documents-{$report['asOf']}", [
                $party => 'party', 'Number' => 'number', 'Date' => 'date', 'Due' => 'dueOn', 'Days overdue' => 'daysOverdue',
                'Total' => 'totalMinor', 'Outstanding' => 'outstandingMinor',
            ], $report['documents']);
        }

        return Inertia::render('reports/aged', ['kind' => $kind] + $report);
    }

    /**
     * @param  list<array{id: string, code: string, name: string, amountMinor: int, compareMinor?: int}>  $rows
     * @return list<array<string, int|string>>
     */
    private function sectionRows(string $section, array $rows): array
    {
        return array_map(fn (array $row) => ['section' => $section] + $row, $rows);
    }

    /**
     * @return array<string, string>
     */
    private function statementColumns(bool $compare): array
    {
        return ['Section' => 'section', 'Code' => 'code', 'Account' => 'name', 'Amount' => 'amountMinor']
            + ($compare ? ['Compared with' => 'compareMinor'] : []);
    }

    /**
     * The same number of whole months before a period of whole months, else
     * the same number of days before it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function previousPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($from->day === 1 && $to->isSameDay($to->endOfMonth())) {
            $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;

            return [$from->subMonthsNoOverflow($months), $from->subDay()];
        }

        $days = (int) $from->diffInDays($to) + 1;

        return [$from->subDays($days), $from->subDay()];
    }

    /**
     * @param  list<string>  $options
     */
    private function compare(Request $request, array $options): ?string
    {
        $data = $request->validate(['compare' => ['nullable', Rule::in($options)]]);

        return $data['compare'] ?? null;
    }

    /**
     * ?cost_centre= and ?project=: a value's id, or "none" for lines without one.
     *
     * @return array<string, string> kind => value id or Balances::NONE
     */
    private function dimensionFilter(Request $request): array
    {
        $data = $request->validate([
            'cost_centre' => ['nullable', 'string', 'max:36'],
            'project' => ['nullable', 'string', 'max:36'],
        ]);

        $filter = [];
        foreach (DimensionValue::KINDS as $kind) {
            $value = $data[$kind] ?? null;
            if ($value === null) {
                continue;
            }
            if ($value !== Balances::NONE && (! Str::isUuid($value) || ! DimensionValue::query()->ofKind($kind)->whereKey($value)->exists())) {
                throw ValidationException::withMessages([$kind => 'Choose a '.strtolower(DimensionValue::kindLabel($kind)).' from the list.']);
            }
            $filter[$kind] = $value;
        }

        return $filter;
    }

    /**
     * The chosen filter and every cost centre and project (closed ones too:
     * their past lines still report).
     *
     * @param  array<string, string>  $filter
     * @return array{costCentre: string|null, project: string|null, dimensionOptions: array{costCentres: list<array{id: string, label: string}>, projects: list<array{id: string, label: string}>}}
     */
    private function dimensionProps(array $filter): array
    {
        $values = DimensionValue::query()->orderBy('code')->get();
        $options = fn (string $kind) => array_values($values->where('kind', $kind)
            ->map(fn (DimensionValue $value) => ['id' => $value->id, 'label' => $value->label()])
            ->all());

        return [
            'costCentre' => $filter[DimensionValue::COST_CENTRE] ?? null,
            'project' => $filter[DimensionValue::PROJECT] ?? null,
            'dimensionOptions' => ['costCentres' => $options(DimensionValue::COST_CENTRE), 'projects' => $options(DimensionValue::PROJECT)],
        ];
    }

    /**
     * The table asked for with ?export=, if any.
     *
     * @param  list<string>  $tables
     */
    private function export(Request $request, array $tables): ?string
    {
        $data = $request->validate(['export' => ['nullable', Rule::in($tables)]]);

        return $data['export'] ?? null;
    }

    private function asOf(Request $request): CarbonImmutable
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return isset($data['as_of']) ? CarbonImmutable::parse($data['as_of']) : CarbonImmutable::today();
    }
}
