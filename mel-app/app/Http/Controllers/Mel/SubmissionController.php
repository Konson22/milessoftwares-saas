<?php

namespace App\Http\Controllers\Mel;

use App\Mel\Access;
use App\Mel\Calculator;
use App\Mel\Evidence;
use App\Mel\Models\DimensionCategory;
use App\Mel\Models\EvidenceFile;
use App\Mel\Models\IndicatorTarget;
use App\Mel\Models\ReviewDecision;
use App\Mel\Models\Settings;
use App\Mel\Models\Submission;
use App\Mel\Models\SubmissionValue;
use App\Mel\Review;
use App\Mel\Submissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One result: its values, evidence and review history, and the review
 * steps (each route checks its own permission).
 */
class SubmissionController extends Controller
{
    public function __construct(
        private readonly Submissions $submissions,
        private readonly Review $review,
        private readonly Evidence $evidence,
    ) {}

    public function show(string $submission): Response
    {
        $submission = $this->visible($submission);
        $submission->load(['decisions.user', 'submitter', 'creator', 'verifiedResult.verifier', 'verifiedResult.publisher', 'values', 'evidence']);
        $access = $this->access();
        $sees = (string) $access->sees();
        $version = $submission->version;
        $settings = Settings::settings();
        $userId = $this->user()->id;
        $categories = DimensionCategory::query()->whereIn('id', $submission->values->pluck('category_id')->merge($submission->values->pluck('second_category_id'))->filter())->pluck('name', 'id');
        $breakdowns = $version->breakdowns->keyBy('id');
        $target = IndicatorTarget::query()->where('indicator_id', $submission->indicator_id)->where('reporting_period_id', $submission->reporting_period_id)
            ->where('unit_id', $submission->unit_id)->whereNull('superseded_at')->first();
        $open = in_array($submission->status, [Submission::SUBMITTED, Submission::UNDER_REVIEW], true);

        return Inertia::render('mel/submissions/show', [
            'submission' => [
                'id' => $submission->id,
                'status' => $submission->status,
                'revision' => $submission->revision,
                'source' => $submission->source,
                'programme' => ['id' => $submission->programme_id, 'code' => $submission->programme->code],
                'indicator' => ['id' => $submission->indicator_id, 'code' => $submission->indicator->code],
                'period' => ['id' => $submission->reporting_period_id, 'label' => $submission->period->label],
                'unit' => ['id' => $submission->unit_id, 'code' => $submission->unit->code, 'name' => $submission->unit->name],
                'value' => $submission->value,
                'numerator' => $submission->numerator,
                'denominator' => $submission->denominator,
                'computed' => Calculator::valueOf($version, $submission->only(['value', 'numerator', 'denominator'])),
                'narrative' => $submission->narrative,
                'deviationNote' => $submission->deviation_note,
                'createdBy' => $submission->creator->name,
                'submittedBy' => $submission->submitter?->name,
                'submittedAt' => $submission->submitted_at?->toIso8601String(),
                'verified' => $submission->verifiedResult === null ? null : [
                    'by' => $submission->verifiedResult->verifier->name,
                    'at' => $submission->verifiedResult->verified_at->toIso8601String(),
                    'publishedBy' => $submission->verifiedResult->publisher?->name,
                    'publishedAt' => $submission->verifiedResult->published_at?->toIso8601String(),
                ],
                'supersedesId' => $submission->supersedes_id,
            ],
            'version' => IndicatorController::version($version),
            'target' => $target === null ? null : ['value' => $target->value, 'min' => $target->min_value, 'max' => $target->max_value],
            'cells' => $submission->values->map(fn (SubmissionValue $cell) => [
                'breakdown' => $breakdowns->get($cell->breakdown_id)?->label(),
                'category' => $categories->get($cell->category_id).($cell->second_category_id === null ? '' : ' · '.$categories->get($cell->second_category_id)),
                'value' => Calculator::valueOf($version, $cell->only(['value', 'numerator', 'denominator'])),
                'numerator' => $cell->numerator,
                'denominator' => $cell->denominator,
            ])->values(),
            'evidence' => $submission->evidence
                ->filter(fn (EvidenceFile $file) => Evidence::visibleTo($file, $sees))
                ->map(fn (EvidenceFile $file) => ['id' => $file->id, 'name' => $file->original_name, 'size' => $file->size, 'shareWithDonors' => $file->share_with_donors])
                ->values(),
            // Reviewers' comments are internal.
            'decisions' => $sees === Access::SEE_ALL ? $submission->decisions->map(fn (ReviewDecision $decision) => [
                'action' => $decision->action,
                'comment' => $decision->comment,
                'user' => $decision->user->name,
                'at' => $decision->created_at->toIso8601String(),
            ])->values() : [],
            'can' => [
                'edit' => $submission->isEditable() && $access->can('mel.data.enter'),
                'discard' => $submission->status === Submission::DRAFT && $access->can('mel.data.enter'),
                'review' => $submission->status === Submission::SUBMITTED && $access->can('mel.data.review')
                    && ! ($settings->verifier_must_differ && $submission->submitted_by === $userId),
                'verify' => $open && $access->can('mel.data.review') && ! ($settings->verifier_must_differ && $submission->submitted_by === $userId),
                'reject' => $open && $access->can('mel.data.review'),
                'publish' => $submission->status === Submission::VERIFIED && $access->can('mel.results.publish')
                    && ! ($settings->publisher_must_differ && $submission->verifiedResult?->verified_by === $userId),
                'correct' => in_array($submission->status, Submission::STANDING, true) && $access->can('mel.data.enter'),
            ],
            'ownSubmission' => $submission->submitted_by === $userId && $settings->verifier_must_differ,
        ]);
    }

    public function submit(string $submission): RedirectResponse
    {
        $submission = $this->visible($submission);
        $this->submissions->submit($submission, $this->user());

        return back()->with('success', 'Submitted for review.');
    }

    public function discard(string $submission): RedirectResponse
    {
        $submission = $this->visible($submission);
        $this->submissions->discard($submission, $this->user());

        return to_route('mel.data.index', ['programme' => $submission->programme_id, 'period' => $submission->reporting_period_id])->with('success', 'Draft discarded.');
    }

    public function startReview(string $submission): RedirectResponse
    {
        $this->review->startReview($this->visible($submission), $this->user());

        return back()->with('success', 'Marked as under review by you.');
    }

    public function verify(Request $request, string $submission): RedirectResponse
    {
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:5000']])['comment'] ?? null;
        $this->review->verify($this->visible($submission), $comment, $this->user());

        return back()->with('success', 'Verified. The value now counts in internal reports; publish it to share it.');
    }

    public function reject(Request $request, string $submission): RedirectResponse
    {
        $comment = $request->validate(['comment' => ['required', 'string', 'max:5000']])['comment'];
        $this->review->reject($this->visible($submission), $comment, $this->user());

        return back()->with('success', 'Sent back to the submitter for correction.');
    }

    public function publish(string $submission): RedirectResponse
    {
        $this->review->publish($this->visible($submission), $this->user());

        return back()->with('success', 'Published.');
    }

    public function attach(Request $request, string $submission): RedirectResponse
    {
        $submission = $this->visible($submission);
        $data = $request->validate(['file' => ['required', 'file', 'max:'.Evidence::MAX_KILOBYTES], 'share_with_donors' => ['boolean']]);
        $file = $request->file('file');
        assert($file instanceof UploadedFile);

        $this->evidence->attachToSubmission($submission, $file, (bool) ($data['share_with_donors'] ?? false), $this->user());

        return back()->with('success', 'Evidence added.');
    }

    public function detach(string $evidence): RedirectResponse
    {
        $file = $this->evidenceFile($evidence);
        abort_unless($this->access()->can($file->submission_id === null ? 'mel.frameworks.manage' : 'mel.data.enter'), 403);
        // A result's evidence is removed by whoever uploaded it.
        abort_if($file->submission_id !== null && $file->uploaded_by !== $this->user()->id, 403);
        $this->evidence->remove($file, $this->user());

        return back()->with('success', 'Evidence removed.');
    }

    /**
     * Always a download, never shown inline: uploaded files are not trusted
     * to render in the browser.
     */
    public function download(string $evidence): StreamedResponse
    {
        $file = $this->evidenceFile($evidence);
        abort_unless(Evidence::visibleTo($file, (string) $this->access()->sees()), 404);

        return Storage::disk('local')->download($file->path, $file->original_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A submission the member may see at their stage of results, or 404:
     * donor viewers only published ones, evaluators verified ones.
     */
    private function visible(string $id): Submission
    {
        $submission = $this->submission($id);

        $visible = match ($this->access()->sees()) {
            Access::SEE_ALL => true,
            Access::SEE_VERIFIED => in_array($submission->status, [...Submission::STANDING, Submission::SUPERSEDED], true) && $submission->verifiedResult()->exists(),
            Access::SEE_PUBLISHED => $submission->status === Submission::PUBLISHED,
            default => false,
        };
        abort_unless($visible, 404);

        return $submission;
    }

    /**
     * Evidence in a programme (and unit) the member reaches, or 404.
     */
    private function evidenceFile(string $id): EvidenceFile
    {
        $file = EvidenceFile::query()->with('submission.period')->findOrFail($id);
        $programme = $this->programme($file->programme_id);

        if ($file->submission !== null) {
            abort_unless($this->access()->reachesUnit($programme, $file->submission->unit_id), 404);
            abort_unless($this->access()->reachesPeriod($programme, $file->submission->period->starts_on, $file->submission->period->ends_on), 404);
        }

        return $file;
    }
}
