<?php

namespace App\Mel;

use App\Mel\Models\Settings;
use App\Mel\Models\Submission;
use App\Mel\Models\VerifiedResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Verification and publication (ADR-0011 §5):
 *
 * submitted → under_review → verified → published, or back to the
 * submitter as rejected (a reason is required). Each step is its own
 * permission (mel.data.review, mel.results.publish), checked by the
 * routes; here the transitions, the separation of duties the organization
 * asks for, and row locks so no step is applied twice.
 *
 * Verifying copies the value, frozen, into verified_results. A verified
 * correction replaces the result it corrects, published or not: until the
 * correction is published, donor views show no value for that cell.
 */
class Review
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Submissions $submissions,
    ) {}

    public function startReview(Submission $submission, User $user): void
    {
        $this->transition($submission, [Submission::SUBMITTED], function (Submission $submission) use ($user) {
            $this->assertNotSubmitter($submission, $user);
            $submission->update(['status' => Submission::UNDER_REVIEW, 'reviewer_id' => $user->id]);
            $this->submissions->decide($submission, 'review_started', null, $user);
            $this->audit->record('submission.review_started', $submission, $user);
        });
    }

    public function verify(Submission $submission, ?string $comment, User $user): void
    {
        $this->transition($submission, [Submission::SUBMITTED, Submission::UNDER_REVIEW], function (Submission $submission) use ($comment, $user) {
            $this->assertNotSubmitter($submission, $user);

            if ($submission->supersedes_id !== null) {
                $previous = Submission::query()->whereKey($submission->supersedes_id)->lockForUpdate()->firstOrFail();
                $previous->update(['status' => Submission::SUPERSEDED]);
                VerifiedResult::query()->where('submission_id', $previous->id)->update(['superseded_at' => now()]);
            }

            $submission->update(['status' => Submission::VERIFIED, 'reviewer_id' => $user->id]);
            VerifiedResult::query()->create([
                'submission_id' => $submission->id,
                'programme_id' => $submission->programme_id,
                'indicator_id' => $submission->indicator_id,
                'indicator_version_id' => $submission->indicator_version_id,
                'reporting_period_id' => $submission->reporting_period_id,
                'unit_id' => $submission->unit_id,
                'value' => $submission->value,
                'numerator' => $submission->numerator,
                'denominator' => $submission->denominator,
                'cells' => $submission->values()->get()->map(fn ($cell) => $cell->only(['breakdown_id', 'category_id', 'second_category_id', 'value', 'numerator', 'denominator']))->values()->all() ?: null,
                'verified_by' => $user->id,
                'verified_at' => now(),
                'created_at' => now(),
            ]);
            $this->submissions->decide($submission, 'verified', $comment, $user);
            $this->audit->record('submission.verified', $submission, $user, ['revision' => $submission->revision > 1 ? $submission->revision : null]);
        });
    }

    public function reject(Submission $submission, string $reason, User $user): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['comment' => 'Say what needs correcting.']);
        }

        $this->transition($submission, [Submission::SUBMITTED, Submission::UNDER_REVIEW], function (Submission $submission) use ($reason, $user) {
            $submission->update(['status' => Submission::REJECTED, 'reviewer_id' => null]);
            $this->submissions->decide($submission, 'rejected', $reason, $user);
            $this->audit->record('submission.rejected', $submission, $user, ['reason' => $reason]);
        });
    }

    public function publish(Submission $submission, User $user): void
    {
        $this->transition($submission, [Submission::VERIFIED], function (Submission $submission) use ($user) {
            $verified = VerifiedResult::query()->where('submission_id', $submission->id)->lockForUpdate()->firstOrFail();

            if (Settings::settings()->publisher_must_differ && $verified->verified_by === $user->id) {
                throw ValidationException::withMessages(['submission' => 'You verified this result. Someone else publishes it.']);
            }

            $verified->update(['published_by' => $user->id, 'published_at' => now()]);
            $submission->update(['status' => Submission::PUBLISHED]);
            $this->submissions->decide($submission, 'published', null, $user);
            $this->audit->record('submission.published', $submission, $user);
        });
    }

    /**
     * Publishes several verified results at once; those the member may not
     * publish (separation of duties) are left as they are.
     *
     * @param  iterable<Submission>  $submissions
     * @return array{published: int, skipped: int}
     */
    public function publishMany(iterable $submissions, User $user): array
    {
        $published = 0;
        $skipped = 0;

        foreach ($submissions as $submission) {
            try {
                $this->publish($submission, $user);
                $published++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return ['published' => $published, 'skipped' => $skipped];
    }

    /**
     * @param  list<string>  $from
     * @param  callable(Submission): void  $apply
     */
    private function transition(Submission $submission, array $from, callable $apply): void
    {
        DB::transaction(function () use ($submission, $from, $apply) {
            $locked = Submission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['submission' => 'This result has moved on meanwhile (it is now '.str_replace('_', ' ', $locked->status).').']);
            }

            $apply($submission->setRawAttributes($locked->getAttributes(), true));
        });
    }

    private function assertNotSubmitter(Submission $submission, User $user): void
    {
        if (Settings::settings()->verifier_must_differ && $submission->submitted_by === $user->id) {
            throw ValidationException::withMessages(['submission' => 'You submitted this result. Someone else reviews it.']);
        }
    }
}
