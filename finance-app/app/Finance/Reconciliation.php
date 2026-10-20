<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\BankRule;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation: statements imported per bank account, each line
 * matched to the one ledger line it clears (same account, same signed
 * amount), and the statement reconciled when every line is matched and its
 * lines add up from its opening to its closing balance.
 *
 * Statements follow each other: one opens with the previous one's closing
 * balance, and lines dated on or before the previous one's end are skipped
 * as already imported. They are reconciled in order and undone from the
 * latest.
 */
class Reconciliation
{
    /** Days either side of a statement line a ledger line is suggested within. */
    public const SUGGEST_DAYS = 30;

    /** Days either side within which a single candidate is matched automatically. */
    public const AUTO_DAYS = 3;

    public function __construct(private readonly Banking $banking) {}

    /**
     * @param  list<array{date: CarbonImmutable, description: string, reference: string|null, amount: int}>  $rows
     * @return array{statement: BankStatement, skipped: int}
     *
     * @throws ValidationException
     */
    public function import(Account $bank, CarbonImmutable $endsOn, ?int $opening, int $closing, ?string $reference, array $rows, User $by): array
    {
        if (! $bank->isBank()) {
            throw ValidationException::withMessages(['account_id' => 'Choose a bank or cash account.']);
        }

        return DB::transaction(function () use ($bank, $endsOn, $opening, $closing, $reference, $rows, $by) {
            Account::query()->whereKey($bank->id)->lockForUpdate()->first();
            $previous = $this->latest($bank);

            if ($previous !== null) {
                if ($endsOn->lessThanOrEqualTo($previous->ends_on)) {
                    throw ValidationException::withMessages(['ends_on' => "The last statement for {$bank->name} ends on {$previous->ends_on->format('j M Y')}; this one must end later."]);
                }
                $opening = $previous->closing_balance_minor;
            } elseif ($opening === null) {
                throw ValidationException::withMessages(['opening_balance' => 'Enter the opening balance of the first statement.']);
            }

            $kept = array_values(array_filter($rows, fn (array $row) => $previous === null || $row['date']->greaterThan($previous->ends_on)));
            foreach ($kept as $index => $row) {
                if ($row['date']->greaterThan($endsOn)) {
                    throw ValidationException::withMessages(["rows.{$index}" => "A line is dated {$row['date']->toDateString()}, after the statement date."]);
                }
            }
            if ($kept === [] && $rows !== []) {
                throw ValidationException::withMessages(['rows' => 'Every line is on or before the end of the last statement: nothing new to import.']);
            }

            $startsOn = $previous?->ends_on->addDay()
                ?? (collect($kept)->min('date') ?? $endsOn);

            $statement = BankStatement::query()->create([
                'account_id' => $bank->id,
                'reference' => $reference,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'opening_balance_minor' => $opening,
                'closing_balance_minor' => $closing,
                'imported_by' => $by->id,
            ]);

            usort($kept, fn (array $a, array $b) => $a['date'] <=> $b['date']);
            foreach ($kept as $position => $row) {
                $statement->lines()->create([
                    'account_id' => $bank->id,
                    'position' => $position + 1,
                    'line_date' => $row['date'],
                    'description' => mb_substr($row['description'], 0, 255),
                    'reference' => $row['reference'] === null ? null : mb_substr($row['reference'], 0, 64),
                    'amount_minor' => $row['amount'],
                ]);
            }

            return ['statement' => $statement, 'skipped' => count($rows) - count($kept)];
        });
    }

    /**
     * Ledger lines on the line's bank account for the same signed amount,
     * not cleared by any statement line, closest in date first.
     *
     * @return Collection<int, JournalLine>
     */
    public function candidates(BankStatementLine $line, int $days = self::SUGGEST_DAYS, int $limit = 5): Collection
    {
        return $this->uncleared($line->account_id)
            ->where($line->amount_minor > 0 ? 'journal_lines.debit_minor' : 'journal_lines.credit_minor', abs($line->amount_minor))
            ->whereDate('journals.entry_date', '>=', $line->line_date->subDays($days))
            ->whereDate('journals.entry_date', '<=', $line->line_date->addDays($days))
            ->get()
            ->sortBy(fn (JournalLine $candidate) => abs($line->line_date->diffInDays(CarbonImmutable::parse((string) $candidate->getAttribute('entry_date')))))
            ->take($limit)
            ->values();
    }

    /**
     * @throws ValidationException when the statement is reconciled, or the ledger line is not a match
     */
    public function match(BankStatementLine $line, JournalLine $journalLine, User $by): BankStatementLine
    {
        return DB::transaction(function () use ($line, $journalLine, $by) {
            $locked = $this->openLine($line);
            $journalLine = JournalLine::query()->whereKey($journalLine->id)->lockForUpdate()->firstOrFail();

            if ($locked->journal_line_id !== null) {
                throw ValidationException::withMessages(['line' => 'This line is already matched; unmatch it first.']);
            }
            if ($journalLine->account_id !== $locked->account_id || $journalLine->debit_minor - $journalLine->credit_minor !== $locked->amount_minor) {
                throw ValidationException::withMessages(['line' => 'Match a ledger line on the same account for the same amount.']);
            }
            if (BankStatementLine::query()->where('journal_line_id', $journalLine->id)->exists()) {
                throw ValidationException::withMessages(['line' => 'That ledger line is already cleared by another statement line.']);
            }

            $locked->forceFill(['journal_line_id' => $journalLine->id, 'matched_at' => now(), 'matched_by' => $by->id])->save();

            return $locked;
        });
    }

    /**
     * @throws ValidationException when the statement is reconciled
     */
    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        return DB::transaction(function () use ($line) {
            $locked = $this->openLine($line);
            $locked->forceFill(['journal_line_id' => null, 'matched_at' => null, 'matched_by' => null])->save();

            return $locked;
        });
    }

    /**
     * Enters an unmatched line in the books as money in or out against
     * another account, and matches it to the bank side of that entry.
     *
     * @throws ValidationException when the statement is reconciled, the line is matched, or the day is locked
     */
    public function enter(BankStatementLine $line, Account $other, ?string $memo, User $by): BankStatementLine
    {
        return DB::transaction(function () use ($line, $other, $memo, $by) {
            $locked = $this->openLine($line);
            if ($locked->journal_line_id !== null) {
                throw ValidationException::withMessages(['line' => 'This line is already matched.']);
            }

            $bank = Account::query()->findOrFail($locked->account_id);
            $description = $memo ?? $locked->description;
            $journal = $locked->amount_minor > 0
                ? $this->banking->moneyIn($bank, $other, $locked->amount_minor, $locked->line_date, $description, $by)
                : $this->banking->moneyOut($bank, $other, -$locked->amount_minor, $locked->line_date, $description, $by);

            return $this->match($locked, $journal->lines()->where('account_id', $bank->id)->sole(), $by);
        });
    }

    /**
     * Matches every open line that has exactly one candidate within a few
     * days, then enters the lines with no candidate at all that a rule
     * covers.
     *
     * @return array{matched: int, entered: int}
     */
    public function autoMatch(BankStatement $statement, User $by): array
    {
        return DB::transaction(function () use ($statement, $by) {
            $this->openStatement($statement);
            $rules = BankRule::query()->with('account')->orderBy('position')->get();
            $matched = 0;
            $entered = 0;

            foreach ($statement->lines()->whereNull('journal_line_id')->get() as $line) {
                $near = $this->candidates($line, self::AUTO_DAYS, 2);
                if ($near->count() === 1) {
                    $this->match($line, $near->first(), $by);
                    $matched++;

                    continue;
                }
                if ($near->isNotEmpty() || $this->candidates($line, limit: 1)->isNotEmpty()) {
                    continue;
                }

                $rule = $rules->first(fn (BankRule $rule) => $rule->account->active && $rule->applies($line));
                if ($rule !== null) {
                    $this->enter($line, $rule->account, $rule->memo, $by);
                    $entered++;
                }
            }

            return ['matched' => $matched, 'entered' => $entered];
        });
    }

    /**
     * @throws ValidationException when a line is unmatched, the lines do not add up, or an earlier statement is open
     */
    public function reconcile(BankStatement $statement, User $by): BankStatement
    {
        return DB::transaction(function () use ($statement, $by) {
            $locked = $this->openStatement($statement);

            $unmatched = $locked->lines()->whereNull('journal_line_id')->count();
            if ($unmatched > 0) {
                throw ValidationException::withMessages(['statement' => "{$unmatched} ".($unmatched === 1 ? 'line is' : 'lines are').' not matched yet.']);
            }
            $sum = (int) $locked->lines()->sum('amount_minor');
            if ($locked->opening_balance_minor + $sum !== $locked->closing_balance_minor) {
                throw ValidationException::withMessages(['statement' => 'The lines take the opening balance to '.Money::toMajor($locked->opening_balance_minor + $sum).', not the closing balance of '.Money::toMajor($locked->closing_balance_minor).'. A line is missing or the balance was typed wrong.']);
            }
            $earlier = BankStatement::query()->where('account_id', $locked->account_id)->whereDate('ends_on', '<', $locked->ends_on)->whereNull('reconciled_at')->exists();
            if ($earlier) {
                throw ValidationException::withMessages(['statement' => 'Reconcile the earlier statements of this account first.']);
            }

            $locked->forceFill(['reconciled_at' => now(), 'reconciled_by' => $by->id])->save();

            return $locked;
        });
    }

    /**
     * @throws ValidationException when it is not reconciled, or a later statement is
     */
    public function undo(BankStatement $statement): BankStatement
    {
        return DB::transaction(function () use ($statement) {
            $locked = BankStatement::query()->whereKey($statement->id)->lockForUpdate()->firstOrFail();

            if (! $locked->reconciled()) {
                throw ValidationException::withMessages(['statement' => 'This statement is not reconciled.']);
            }
            if (BankStatement::query()->where('account_id', $locked->account_id)->whereDate('ends_on', '>', $locked->ends_on)->whereNotNull('reconciled_at')->exists()) {
                throw ValidationException::withMessages(['statement' => 'Undo the later statements of this account first.']);
            }

            $locked->forceFill(['reconciled_at' => null, 'reconciled_by' => null])->save();

            return $locked;
        });
    }

    /**
     * Removes the account's latest statement while it is not reconciled.
     *
     * @throws ValidationException
     */
    public function delete(BankStatement $statement): void
    {
        DB::transaction(function () use ($statement) {
            $locked = $this->openStatement($statement);
            if ($this->latest($locked->account)?->id !== $locked->id) {
                throw ValidationException::withMessages(['statement' => 'Only the latest statement of an account can be removed.']);
            }

            $locked->lines()->delete();
            $locked->delete();
        });
    }

    /**
     * Balance per bank against balance per books on the statement's last
     * day: the statement's closing balance, plus ledger lines not yet
     * cleared by a statement line dated by then (deposits in transit,
     * outstanding payments), less statement lines cleared against ledger
     * lines dated later, should be the ledger balance. What is left is
     * unexplained: usually the first statement's opening balance differing
     * from the books then, or lines not matched yet.
     *
     * @return array{asOf: string, statementMinor: int, depositsMinor: int, paymentsMinor: int, clearedAheadMinor: int, expectedMinor: int, booksMinor: int, differenceMinor: int, uncleared: list<array{id: string, journalId: string, number: string, date: string, memo: string, amountMinor: int}>}
     */
    public function report(BankStatement $statement): array
    {
        $end = $statement->ends_on;
        $first = BankStatement::query()->where('account_id', $statement->account_id)->min('starts_on');
        $firstStart = CarbonImmutable::parse((string) $first);

        $books = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $statement->account_id)
            ->whereDate('journals.entry_date', '<=', $end)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) AS balance')
            ->toBase()
            ->value('balance');

        $clearedBy = BankStatementLine::query()
            ->whereNotNull('journal_line_id')
            ->where('account_id', $statement->account_id)
            ->whereDate('line_date', '<=', $end)
            ->select('journal_line_id');

        $uncleared = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $statement->account_id)
            ->whereDate('journals.entry_date', '>=', $firstStart)
            ->whereDate('journals.entry_date', '<=', $end)
            ->whereNotIn('journal_lines.id', $clearedBy)
            ->orderBy('journals.entry_date')
            ->orderBy('journals.number')
            ->select('journal_lines.*', 'journals.number', 'journals.entry_date', 'journals.memo')
            ->get();

        $clearedAhead = (int) BankStatementLine::query()
            ->join('journal_lines', 'journal_lines.id', '=', 'bank_statement_lines.journal_line_id')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('bank_statement_lines.account_id', $statement->account_id)
            ->whereDate('bank_statement_lines.line_date', '<=', $end)
            ->whereDate('journals.entry_date', '>', $end)
            ->sum('bank_statement_lines.amount_minor');

        $deposits = (int) $uncleared->sum('debit_minor');
        $payments = (int) $uncleared->sum('credit_minor');
        $expected = $statement->closing_balance_minor + $deposits - $payments - $clearedAhead;

        return [
            'asOf' => $end->toDateString(),
            'statementMinor' => $statement->closing_balance_minor,
            'depositsMinor' => $deposits,
            'paymentsMinor' => $payments,
            'clearedAheadMinor' => $clearedAhead,
            'expectedMinor' => $expected,
            'booksMinor' => (int) $books,
            'differenceMinor' => (int) $books - $expected,
            'uncleared' => array_values($uncleared->take(200)->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'journalId' => $line->journal_id,
                'number' => (string) $line->getAttribute('number'),
                'date' => substr((string) $line->getAttribute('entry_date'), 0, 10),
                'memo' => (string) $line->getAttribute('memo'),
                'amountMinor' => $line->debit_minor - $line->credit_minor,
            ])->all()),
        ];
    }

    public function latest(Account $bank): ?BankStatement
    {
        return BankStatement::query()->where('account_id', $bank->id)->orderByDesc('ends_on')->first();
    }

    /**
     * Ledger lines of a bank account that no statement line clears.
     *
     * @return Builder<JournalLine>
     */
    private function uncleared(string $accountId): Builder
    {
        return JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $accountId)
            ->whereNotIn('journal_lines.id', BankStatementLine::query()->whereNotNull('journal_line_id')->select('journal_line_id'))
            ->select('journal_lines.*', 'journals.number', 'journals.entry_date', 'journals.memo', 'journals.source_app');
    }

    private function openLine(BankStatementLine $line): BankStatementLine
    {
        $locked = BankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
        $this->openStatement($locked->statement);

        return $locked;
    }

    private function openStatement(BankStatement $statement): BankStatement
    {
        $locked = BankStatement::query()->whereKey($statement->id)->lockForUpdate()->firstOrFail();
        if ($locked->reconciled()) {
            throw ValidationException::withMessages(['statement' => 'This statement is reconciled; undo the reconciliation to change it.']);
        }

        return $locked;
    }
}
