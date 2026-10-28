<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\DimensionValue;
use Illuminate\Validation\ValidationException;

/**
 * Journal lines kept for later (recurring journals, journals waiting for
 * approval): account and dimension ids with amounts in minor units, turned
 * into ledger lines with today's accounts when they are posted.
 */
final class StoredLines
{
    /**
     * @param  list<array{account_id: string, debit_minor: int, credit_minor: int, description: string|null, cost_centre_id: string|null, project_id: string|null}>  $lines
     * @return list<array{account: Account, debit: int, credit: int, description: string|null, cost_centre: DimensionValue|null, project: DimensionValue|null}>
     *
     * @throws ValidationException when an account no longer exists
     */
    public static function toLedger(array $lines): array
    {
        $accounts = Account::query()->whereIn('id', array_column($lines, 'account_id'))->get()->keyBy('id');
        $values = DimensionValue::query()->whereIn('id', array_filter([...array_column($lines, 'cost_centre_id'), ...array_column($lines, 'project_id')]))->get()->keyBy('id');

        $ledgerLines = [];
        foreach ($lines as $index => $line) {
            $account = $accounts->get($line['account_id']) ?? throw ValidationException::withMessages(["lines.{$index}.account_id" => 'An account on this journal no longer exists.']);
            $ledgerLines[] = [
                'account' => $account,
                'debit' => (int) $line['debit_minor'],
                'credit' => (int) $line['credit_minor'],
                'description' => $line['description'] ?? null,
                'cost_centre' => isset($line['cost_centre_id']) ? $values->get($line['cost_centre_id']) : null,
                'project' => isset($line['project_id']) ? $values->get($line['project_id']) : null,
            ];
        }

        return $ledgerLines;
    }

    /**
     * The total of the debits.
     *
     * @param  list<array{debit_minor: int}>  $lines
     */
    public static function total(array $lines): int
    {
        return (int) array_sum(array_column($lines, 'debit_minor'));
    }
}
