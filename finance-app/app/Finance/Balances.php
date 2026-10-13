<?php

namespace App\Finance;

use App\Models\JournalLine;
use Carbon\CarbonImmutable;

/**
 * Debit and credit totals per account, straight from the posted lines.
 */
class Balances
{
    /**
     * @return array<string, array{debits: int, credits: int}> keyed by account id
     */
    public function totals(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $rows = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->when($from, fn ($query) => $query->whereDate('journals.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('journals.entry_date', '<=', $to))
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
}
