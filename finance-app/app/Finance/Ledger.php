<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Double entry. Every journal has two or more lines, each a debit or a
 * credit to an active account, and debits equal credits. Journals are never
 * edited: a mistake is reversed by another journal. PostgreSQL checks the
 * balance again when the transaction commits.
 */
class Ledger
{
    public function __construct(private readonly DocumentNumbers $numbers) {}

    /**
     * @param  list<array{account: Account, debit?: int, credit?: int, description?: string|null}>  $lines
     * @param  array{source_app?: string, source_type?: string, source_reference?: string|null, branch_id?: string|null}  $source
     *
     * @throws ValidationException when the lines do not make a valid journal
     */
    public function post(CarbonImmutable $date, string $memo, array $lines, array $source = [], ?User $by = null): Journal
    {
        $this->check($lines);

        return DB::transaction(function () use ($date, $memo, $lines, $source, $by) {
            $journal = new Journal([
                'number' => $this->numbers->next('journal', 'JV', 6),
                'entry_date' => $date,
                'memo' => $memo,
                'source_app' => $source['source_app'] ?? 'finance',
                'source_type' => $source['source_type'] ?? Journal::MANUAL,
                'source_reference' => $source['source_reference'] ?? null,
                'branch_id' => $source['branch_id'] ?? null,
                'created_by' => $by?->id,
            ]);
            $journal->save();

            foreach ($lines as $position => $line) {
                $journal->lines()->create([
                    'account_id' => $line['account']->id,
                    'position' => $position + 1,
                    'debit_minor' => $line['debit'] ?? 0,
                    'credit_minor' => $line['credit'] ?? 0,
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $journal;
        });
    }

    /**
     * Posts the mirror image of a journal and links the two.
     *
     * @throws ValidationException when it is already reversed, or is itself a reversal
     */
    public function reverse(Journal $journal, CarbonImmutable $date, string $memo, ?User $by = null, string $sourceType = Journal::REVERSAL): Journal
    {
        return DB::transaction(function () use ($journal, $date, $memo, $by, $sourceType) {
            $locked = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            if ($locked->reversed_by_id !== null) {
                throw ValidationException::withMessages(['journal' => "{$locked->number} is already reversed."]);
            }
            if ($locked->reversal_of_id !== null) {
                throw ValidationException::withMessages(['journal' => "{$locked->number} is a reversal; post a new journal instead."]);
            }

            $lines = [];
            foreach ($locked->lines()->with('account')->get() as $line) {
                $lines[] = [
                    'account' => $line->account,
                    'debit' => $line->credit_minor,
                    'credit' => $line->debit_minor,
                    'description' => $line->description,
                ];
            }

            $reversal = $this->post($date, $memo, $lines, [
                'source_app' => $locked->source_app,
                'source_type' => $sourceType,
                'source_reference' => $locked->number,
                'branch_id' => $locked->branch_id,
            ], $by);
            $reversal->forceFill(['reversal_of_id' => $locked->id])->save();
            $locked->forceFill(['reversed_by_id' => $reversal->id])->save();

            return $reversal;
        });
    }

    /**
     * @param  list<array{account: Account, debit?: int, credit?: int, description?: string|null}>  $lines
     */
    private function check(array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal needs at least two lines.']);
        }

        $debits = 0;
        $credits = 0;

        foreach ($lines as $index => $line) {
            $debit = $line['debit'] ?? 0;
            $credit = $line['credit'] ?? 0;

            if ($debit < 0 || $credit < 0 || ($debit === 0) === ($credit === 0)) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Each line is either a debit or a credit, and not zero.']);
            }
            if (! $line['account']->active) {
                throw ValidationException::withMessages(["lines.{$index}" => "{$line['account']->label()} is closed."]);
            }

            $debits += $debit;
            $credits += $credit;
        }

        if ($debits !== $credits) {
            throw ValidationException::withMessages(['lines' => 'Debits and credits must be equal.']);
        }
    }
}
