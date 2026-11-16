<?php

namespace App\Collect;

use App\Collect\Models\Submission;
use App\Mel\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review of collected data (ADR-0012 §10), kept apart from M&E's
 * verification of indicator results: accepting a survey response never
 * makes a verified result.
 *
 *   submitted → under_review → accepted
 *                            → rejected (reason required; kept, excluded)
 *                            → correction_requested → (corrected) → submitted
 *   accepted | rejected → reopened (reason required) → under_review
 *
 * Every step locks the submission, writes a review decision and an audit
 * entry in one transaction. The reviewer is not the author unless the
 * workspace turned that rule off (Settings::collect_reviewer_must_differ).
 */
class Review
{
    public function __construct(private readonly Submissions $submissions) {}

    public function start(Submission $submission, User $reviewer): void
    {
        $this->step($submission, $reviewer, [Submission::SUBMITTED], Submission::UNDER_REVIEW, 'review_started');
    }

    public function accept(Submission $submission, User $reviewer, ?string $comment = null): void
    {
        $this->step($submission, $reviewer, Submission::TO_REVIEW, Submission::ACCEPTED, 'accepted', $comment);
    }

    public function reject(Submission $submission, User $reviewer, string $reason): void
    {
        $this->step($submission, $reviewer, Submission::TO_REVIEW, Submission::REJECTED, 'rejected', self::required($reason, 'reason', 'Say why it is rejected.'));
    }

    /**
     * @param  array<string, string>  $notes  question name → what to fix
     */
    public function requestCorrection(Submission $submission, User $reviewer, string $comment, array $notes = []): void
    {
        $questions = array_keys(Definition::questions($submission->version->definition));
        $notes = array_filter(
            array_map(fn (string $note) => mb_substr(trim($note), 0, 1000), $notes),
            fn (string $note, string $name) => $note !== '' && in_array($name, $questions, true),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->step($submission, $reviewer, Submission::TO_REVIEW, Submission::CORRECTION_REQUESTED, 'correction_requested', self::required($comment, 'comment', 'Say what needs correcting.'), $notes === [] ? null : $notes);
    }

    public function reopen(Submission $submission, User $reviewer, string $reason): void
    {
        $this->step($submission, $reviewer, [Submission::ACCEPTED, Submission::REJECTED], Submission::UNDER_REVIEW, 'reopened', self::required($reason, 'reason', 'Say why it is reopened.'));
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, string>|null  $notes
     */
    private function step(Submission $submission, User $reviewer, array $from, string $to, string $action, ?string $comment = null, ?array $notes = null): void
    {
        DB::transaction(function () use ($submission, $reviewer, $from, $to, $action, $comment, $notes) {
            /** @var Submission $locked */
            $locked = Submission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => match ($locked->status) {
                    Submission::RECEIVING => 'This submission is still receiving its photos.',
                    Submission::ACCEPTED => 'This submission was accepted. Reopen it first.',
                    Submission::REJECTED => 'This submission was rejected. Reopen it first.',
                    Submission::CORRECTION_REQUESTED => 'A correction was asked for. Wait for it, or reject the submission.',
                    default => 'This submission is not at that step.',
                }]);
            }
            if ($locked->author_id === $reviewer->id && Settings::current()->collect_reviewer_must_differ) {
                throw ValidationException::withMessages(['status' => 'You collected this submission, so someone else reviews it.']);
            }

            $locked->forceFill([
                'status' => $to,
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => in_array($to, [Submission::ACCEPTED, Submission::REJECTED], true) ? now() : $locked->reviewed_at,
            ])->save();
            $submission->setRawAttributes($locked->getAttributes(), true);

            $this->submissions->decide($locked, $action, $reviewer, $comment, $notes);
        });
    }

    private static function required(string $text, string $field, string $message): string
    {
        $text = trim($text);
        if ($text === '') {
            throw ValidationException::withMessages([$field => $message]);
        }

        return mb_substr($text, 0, 5000);
    }
}
