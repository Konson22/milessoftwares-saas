<?php

namespace App\Http\Controllers;

use App\Mel\Calculator;
use App\Mel\Review;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What is waiting for review and for publication, across the programmes
 * and units the member reaches.
 */
class VerificationController extends Controller
{
    public function __construct(private readonly Review $review) {}

    public function index(Request $request): Response
    {
        $access = $this->access();
        $programmes = $access->programmes()->whereNull('archived_at')->get();
        $programmeId = $request->string('programme')->toString() ?: null;
        $stage = in_array($request->string('stage')->toString(), ['review', 'publish', 'rejected'], true) ? $request->string('stage')->toString() : 'review';
        $statuses = match ($stage) {
            'publish' => [Submission::VERIFIED],
            'rejected' => [Submission::REJECTED],
            default => [Submission::SUBMITTED, Submission::UNDER_REVIEW],
        };

        $submissions = Submission::query()
            ->whereIn('programme_id', $programmes->pluck('id'))
            ->when($programmeId !== null, fn ($query) => $query->where('programme_id', $programmeId))
            ->whereIn('status', $statuses)
            ->with(['programme', 'indicator', 'version', 'period', 'unit', 'submitter', 'reviewer'])
            ->orderBy('submitted_at')
            ->limit(300)
            ->get()
            ->filter(fn (Submission $submission) => $access->reachesUnit($submission->programme, $submission->unit_id))
            ->values();

        $counts = Submission::query()
            ->whereIn('programme_id', $programmes->pluck('id'))
            ->when($programmeId !== null, fn ($query) => $query->where('programme_id', $programmeId))
            ->whereIn('status', [Submission::SUBMITTED, Submission::UNDER_REVIEW, Submission::VERIFIED, Submission::REJECTED])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('verification/index', [
            'stage' => $stage,
            'programme' => $programmeId,
            'programmes' => $programmes->sortBy('code')->map(fn ($programme) => ['value' => $programme->id, 'label' => $programme->label()])->values(),
            'counts' => [
                'review' => (int) ($counts[Submission::SUBMITTED] ?? 0) + (int) ($counts[Submission::UNDER_REVIEW] ?? 0),
                'publish' => (int) ($counts[Submission::VERIFIED] ?? 0),
                'rejected' => (int) ($counts[Submission::REJECTED] ?? 0),
            ],
            'submissions' => $submissions->map(fn (Submission $submission) => [
                'id' => $submission->id,
                'programme' => $submission->programme->code,
                'indicator' => $submission->indicator->code,
                'name' => $submission->version->name,
                'measure' => $submission->version->measure,
                'decimals' => $submission->version->decimals,
                'unitLabel' => $submission->version->unit_label,
                'period' => $submission->period->label,
                'unit' => $submission->unit->code,
                'value' => Calculator::valueOf($submission->version, $submission->only(['value', 'numerator', 'denominator'])),
                'status' => $submission->status,
                'revision' => $submission->revision,
                'submittedBy' => $submission->submitter?->name,
                'submittedAt' => $submission->submitted_at?->toIso8601String(),
                'reviewer' => $submission->reviewer?->name,
            ]),
            'can' => ['publish' => $access->can('mel.results.publish')],
        ]);
    }

    /**
     * Publishes the ticked verified results.
     */
    public function publish(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:300'], 'ids.*' => ['uuid']])['ids'];
        $submissions = items($ids)->map(fn (string $id) => $this->submission($id));

        $outcome = $this->review->publishMany($submissions, $this->user());

        return back()->with('success', "{$outcome['published']} published".($outcome['skipped'] > 0 ? ", {$outcome['skipped']} left for someone else (you verified them, or they changed)." : '.'));
    }
}
