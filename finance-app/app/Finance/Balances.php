<?php

namespace App\Finance;

use App\Models\DimensionValue;
use App\Models\Journal;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Debit and credit totals per account, straight from the posted lines.
 *
 * Totals can be limited to lines of one cost centre and/or project
 * ($dimensions: kind => value id, or NONE for lines without one).
 */
class Balances
{
    /** Lines with no value for the dimension. */
    public const NONE = 'none';

    /**
     * @param  bool  $withClosing  false leaves out year-end closing journals (and their reopening), for profit and loss
     * @param  array<string, string>  $dimensions  cost_centre / project => value id or NONE
     * @return array<string, array{debits: int, credits: int}> keyed by account id
     */
    public function totals(?CarbonImmutable $from = null, ?CarbonImmutable $to = null, bool $withClosing = true, array $dimensions = []): array
    {
        $rows = $this->lines($from, $to, $withClosing, $dimensions)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit_minor) AS debits, SUM(journal_lines.credit_minor) AS credits')
            ->toBase()
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row->account_id] = ['debits' => (int) $row->debits, 'credits' => (int) $row->credits];
        }

        return $totals;
    }

    /**
     * Totals per account split by the values of one dimension.
     *
     * @return array<string, array<string, array{debits: int, credits: int}>> account id => value id (or NONE) => totals
     */
    public function totalsBy(string $kind, CarbonImmutable $from, CarbonImmutable $to, bool $withClosing = true): array
    {
        $column = $kind === DimensionValue::PROJECT ? 'journal_lines.project_id' : 'journal_lines.cost_centre_id';
        $rows = $this->lines($from, $to, $withClosing, [])
            ->groupBy('journal_lines.account_id', $column)
            ->selectRaw("journal_lines.account_id, {$column} AS value_id, SUM(journal_lines.debit_minor) AS debits, SUM(journal_lines.credit_minor) AS credits")
            ->toBase()
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row->account_id][$row->value_id === null ? self::NONE : (string) $row->value_id] = ['debits' => (int) $row->debits, 'credits' => (int) $row->credits];
        }

        return $totals;
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return Builder<JournalLine>
     */
    private function lines(?CarbonImmutable $from, ?CarbonImmutable $to, bool $withClosing, array $dimensions): Builder
    {
        $query = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->when($from, fn ($query) => $query->whereDate('journals.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('journals.entry_date', '<=', $to))
            ->when(! $withClosing, fn ($query) => $query->whereNotIn('journals.source_type', [Journal::YEAR_CLOSE, Journal::YEAR_REOPEN]));

        foreach ($dimensions as $kind => $value) {
            $column = 'journal_lines.'.DimensionValue::column($kind);
            $value === self::NONE ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }
}
