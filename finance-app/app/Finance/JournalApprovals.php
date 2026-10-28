<?php

namespace App\Finance;

use App\Models\Journal;
use App\Models\JournalDraft;
use App\Models\RecurringJournal;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maker-checker for manual journals, which the organization turns on (from
 * an amount up, or for all). A journal that needs approval waits as a draft,
 * outside the ledger, until someone else holding finance.journals.approve
 * approves it: then it posts, dated as entered, with its preparer as the
 * creator and the approver recorded. Nobody approves their own entry, owners
 * included.
 *
 * A recurring journal is approved once each time it is added or changed;
 * until then it does not post (RecurringJournals).
 *
 * Journals from documents (invoices, bills, payments, banking) and products'
 * events are not held: the documents are their own record.
 */
class JournalApprovals
{
    public const PERMISSION = 'finance.journals.approve';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Periods $periods,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * Whether a manual journal of this total needs approval.
     */
    public function required(int $totalMinor): bool
    {
        $workspace = Workspace::current();
        if ($workspace === null || ! $workspace->journal_approval) {
            return false;
        }

        return $workspace->journal_approval_threshold_minor === null || $totalMinor >= $workspace->journal_approval_threshold_minor;
    }

    /**
     * Turns approval on (for all, or from $thresholdMinor up) or off.
     * Turning it off leaves drafts waiting; they can still be approved.
     *
     * @throws ValidationException before the workspace is provisioned
     */
    public function configure(bool $enabled, ?int $thresholdMinor): Workspace
    {
        $workspace = Workspace::current() ?? throw ValidationException::withMessages(['journal_approval' => 'This organization is not set up yet.']);
        $workspace->forceFill([
            'journal_approval' => $enabled,
            'journal_approval_threshold_minor' => $enabled ? $thresholdMinor : null,
        ])->save();

        return $workspace;
    }

    /**
     * Holds a manual journal for approval. It is checked as if it were
     * posted now, so a draft that could never post is refused at once.
     *
     * @param  list<array{account_id: string, debit_minor: int, credit_minor: int, description: string|null, cost_centre_id: string|null, project_id: string|null}>  $lines
     *
     * @throws ValidationException
     */
    public function submit(CarbonImmutable $date, string $memo, array $lines, User $by): JournalDraft
    {
        $this->ledger->check(StoredLines::toLedger($lines), requireDimensions: true);
        $this->periods->postingDate($date);

        return DB::transaction(fn () => JournalDraft::query()->create([
            'number' => $this->numbers->next('journal_draft', 'JD', 5),
            'entry_date' => $date,
            'memo' => $memo,
            'lines' => $lines,
            'total_minor' => StoredLines::total($lines),
            'status' => JournalDraft::PENDING,
            'submitted_by' => $by->id,
        ]));
    }

    /**
     * Posts the draft.
     *
     * @throws ValidationException when it is not waiting, is the approver's own, or no longer posts (a locked month, a closed account)
     */
    public function approve(JournalDraft $draft, User $by): Journal
    {
        return DB::transaction(function () use ($draft, $by) {
            $locked = $this->pending($draft);
            if ($locked->submitted_by === $by->id) {
                throw ValidationException::withMessages(['draft' => "{$locked->number} is your own entry: someone else approves it."]);
            }

            $journal = $this->ledger->post($locked->entry_date, $locked->memo, StoredLines::toLedger($locked->lines), [
                'require_dimensions' => true,
                'source_reference' => $locked->number,
            ], $locked->submitter);
            $journal->forceFill(['approved_by' => $by->id, 'approved_at' => now()])->save();

            $locked->forceFill(['status' => JournalDraft::APPROVED, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'journal_id' => $journal->id])->save();

            return $journal;
        });
    }

    /**
     * @throws ValidationException when it is not waiting, or is the reviewer's own (withdraw it instead)
     */
    public function reject(JournalDraft $draft, string $reason, User $by): JournalDraft
    {
        return DB::transaction(function () use ($draft, $reason, $by) {
            $locked = $this->pending($draft);
            if ($locked->submitted_by === $by->id) {
                throw ValidationException::withMessages(['draft' => "{$locked->number} is your own entry: withdraw it instead."]);
            }

            $locked->forceFill(['status' => JournalDraft::REJECTED, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $reason])->save();

            return $locked;
        });
    }

    /**
     * Takes back one's own draft while it waits.
     *
     * @throws ValidationException
     */
    public function withdraw(JournalDraft $draft, User $by): JournalDraft
    {
        return DB::transaction(function () use ($draft, $by) {
            $locked = $this->pending($draft);
            if ($locked->submitted_by !== $by->id) {
                throw ValidationException::withMessages(['draft' => "Only the person who entered {$locked->number} can withdraw it."]);
            }

            $locked->forceFill(['status' => JournalDraft::WITHDRAWN, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();

            return $locked;
        });
    }

    /**
     * Whether the recurring journal may post: approved since it was last
     * changed, or not needing approval for its amount.
     */
    public function recurringCleared(RecurringJournal $template): bool
    {
        return $template->approved_at !== null || ! $this->required($template->total());
    }

    /**
     * Approves a recurring journal as it stands.
     *
     * @throws ValidationException when it is the approver's own, or already approved
     */
    public function approveRecurring(RecurringJournal $template, User $by): RecurringJournal
    {
        return DB::transaction(function () use ($template, $by) {
            $locked = RecurringJournal::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($locked->approved_at !== null) {
                throw ValidationException::withMessages(['approve' => "“{$locked->name}” is already approved."]);
            }
            if (($locked->updated_by ?? $locked->created_by) === $by->id) {
                throw ValidationException::withMessages(['approve' => "You set up or last changed “{$locked->name}”: someone else approves it."]);
            }

            $locked->forceFill(['approved_by' => $by->id, 'approved_at' => now()])->save();

            return $locked;
        });
    }

    private function pending(JournalDraft $draft): JournalDraft
    {
        $locked = JournalDraft::query()->with('submitter')->whereKey($draft->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== JournalDraft::PENDING) {
            throw ValidationException::withMessages(['draft' => "{$locked->number} is no longer waiting for approval."]);
        }

        return $locked;
    }
}
