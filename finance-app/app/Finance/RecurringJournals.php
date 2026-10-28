<?php

namespace App\Finance;

use App\Models\Journal;
use App\Models\RecurringJournal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recurring journals: rent, accruals, prepaid amortisation, standard
 * reclasses. A template holds balanced lines and a schedule; every
 * occurrence that is due is posted through the ledger like a manual journal
 * (locked periods refuse it, required cost centres and projects apply), and
 * at most once (a unique index on the template and occurrence).
 *
 * Occurrences are posted in order. One that the ledger refuses (a locked
 * month, a closed account) stops its template, with the reason kept on it,
 * until it is fixed; other templates go on. A reversing template's journals
 * are reversed the day after they are dated, as accruals are.
 *
 * finance:recurring posts what is due every day; "Post due now" does the
 * same for one template.
 *
 * With journal approval on, a template whose journals would need approval
 * posts only once someone other than the person who last saved it has
 * approved it (JournalApprovals); saving it again clears the approval.
 */
class RecurringJournals
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly JournalApprovals $approvals,
    ) {}

    /**
     * Creates a template, or changes one. Once it has posted, its start and
     * frequency stay (they fix the dates already posted).
     *
     * @param  array{name: string, memo: string, frequency: string, starts_on: CarbonImmutable, ends_on: CarbonImmutable|null, reverses: bool, lines: list<array{account_id: string, debit_minor: int, credit_minor: int, description: string|null, cost_centre_id: string|null, project_id: string|null}>}  $data
     *
     * @throws ValidationException
     */
    public function save(?RecurringJournal $template, array $data, User $by): RecurringJournal
    {
        if (! array_key_exists($data['frequency'], RecurringJournal::FREQUENCIES)) {
            throw ValidationException::withMessages(['frequency' => 'Choose how often it is posted.']);
        }
        if ($data['ends_on'] !== null && $data['ends_on']->lessThan($data['starts_on'])) {
            throw ValidationException::withMessages(['ends_on' => 'It ends on or after its first date.']);
        }
        $this->ledger->check(StoredLines::toLedger($data['lines']), requireDimensions: true);

        return DB::transaction(function () use ($template, $data, $by) {
            $template = $template === null ? new RecurringJournal(['created_by' => $by->id]) : RecurringJournal::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();

            if ($template->exists && $template->posted_count > 0
                && ($template->frequency !== $data['frequency'] || ! $template->starts_on->isSameDay($data['starts_on']))) {
                throw ValidationException::withMessages(['starts_on' => 'It has posted already: its first date and frequency stay. Add a new recurring journal instead.']);
            }
            if (RecurringJournal::query()->where('name', $data['name'])->when($template->exists, fn ($query) => $query->whereKeyNot($template->id))->exists()) {
                throw ValidationException::withMessages(['name' => "A recurring journal is already called “{$data['name']}”."]);
            }

            $template->fill(Arr::only($data, ['name', 'memo', 'frequency', 'starts_on', 'ends_on', 'reverses', 'lines']));
            $template->next_on = $template->nextAfter($template->posted_count ?? 0);
            $template->last_error = null;
            // Changed, so approved again (when approval applies).
            $template->updated_by = $by->id;
            $template->approved_by = null;
            $template->approved_at = null;
            $template->save();

            return $template;
        });
    }

    /**
     * Stops or restarts posting. A restarted template catches up on what
     * fell due while it was paused.
     */
    public function setActive(RecurringJournal $template, bool $active): RecurringJournal
    {
        $template->forceFill(['active' => $active, 'last_error' => $active ? null : $template->last_error])->save();

        return $template;
    }

    /**
     * Posts every occurrence due by $today of every active template.
     *
     * @return array{posted: int, failed: int}
     */
    public function runDue(CarbonImmutable $today, ?User $by = null): array
    {
        $posted = 0;
        $failed = 0;

        $due = RecurringJournal::query()->where('active', true)->whereNotNull('next_on')->whereDate('next_on', '<=', $today)->orderBy('next_on')->get();
        foreach ($due as $template) {
            if (! $this->approvals->recurringCleared($template)) {
                continue;
            }
            $result = $this->run($template, $today, $by);
            $posted += $result['posted'];
            $failed += $result['error'] === null ? 0 : 1;
        }

        return ['posted' => $posted, 'failed' => $failed];
    }

    /**
     * Posts the template's occurrences due by $today, in order, each in its
     * own transaction, stopping at the first the ledger refuses.
     *
     * @return array{posted: int, error: string|null}
     */
    public function run(RecurringJournal $template, CarbonImmutable $today, ?User $by = null): array
    {
        if (! $this->approvals->recurringCleared($template)) {
            return ['posted' => 0, 'error' => 'It is waiting for approval.'];
        }
        $posted = 0;

        while (true) {
            try {
                $journal = DB::transaction(function () use ($template, $today, $by) {
                    $locked = RecurringJournal::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
                    if (! $locked->active || $locked->next_on === null || $locked->next_on->greaterThan($today)) {
                        return null;
                    }

                    $occurrence = $locked->posted_count;
                    $on = $locked->next_on;
                    $journal = $this->ledger->post($on, $locked->memo, StoredLines::toLedger($locked->lines), [
                        'source_type' => Journal::RECURRING,
                        'source_reference' => mb_substr($locked->name, 0, 128),
                        'require_dimensions' => true,
                    ], $by);
                    $journal->forceFill(['recurring_journal_id' => $locked->id, 'recurring_occurrence' => $occurrence])->save();

                    if ($locked->reverses) {
                        $reversal = $this->ledger->reverse($journal, $on->addDay(), "Reversal of {$journal->number}: {$locked->memo}", $by, Journal::RECURRING_REVERSAL);
                        $reversal->forceFill(['recurring_journal_id' => $locked->id])->save();
                    }

                    $locked->forceFill([
                        'posted_count' => $occurrence + 1,
                        'next_on' => $locked->nextAfter($occurrence + 1),
                        'last_journal_id' => $journal->id,
                        'last_error' => null,
                    ])->save();

                    return $journal;
                });
            } catch (ValidationException $e) {
                $error = mb_strimwidth((string) Arr::first(Arr::flatten($e->errors())), 0, 250, '…');
                RecurringJournal::query()->whereKey($template->id)->update(['last_error' => $error]);

                return ['posted' => $posted, 'error' => $error];
            }

            if ($journal === null) {
                return ['posted' => $posted, 'error' => null];
            }
            $posted++;
        }
    }
}
