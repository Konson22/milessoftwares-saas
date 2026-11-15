<?php

namespace App\Http\Controllers\Collaboration;

use App\Collaboration\Acting;
use App\Collaboration\Attachments;
use App\Collaboration\Capability;
use App\Collaboration\Models\Attachment;
use App\Collaboration\Models\Obligation;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\Report;
use App\Collaboration\Models\ReportReview;
use App\Collaboration\Models\ReportVersion;
use App\Collaboration\ParticipantAccess;
use App\Collaboration\ReportContent;
use App\Collaboration\Reports;
use App\Collaboration\Routing;
use App\Http\Controllers\Collaboration\Concerns\ActsOnProject;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Partner reports on a project (ADR-0015 §4), for the owner and for each
 * participant, on the same pages. What each may see and do is decided in
 * App\Collaboration\Reports.
 */
class ReportController extends Controller
{
    use ActsOnProject;

    public function index(Request $request): Response
    {
        $acting = $this->acting($request);
        $reports = Reports::listed($acting)->with(['obligation', 'submitter.organization', 'reviewer.organization'])
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('number')->get();
        $obligations = $acting->isOwner()
            ? Obligation::query()->with(['responsible.organization', 'reviewer.organization'])->withCount('reports')->where('project_id', $acting->project->id)->latest()->get()
            : collect();

        return Inertia::render('portal/collaboration/reports', [
            'frame' => $this->frame($request, $acting),
            'reports' => $reports->map(fn (Report $report) => $this->row($report, $acting))->values(),
            'obligations' => $obligations->map(fn (Obligation $obligation) => [
                'id' => $obligation->id,
                'title' => $obligation->title,
                'category' => Report::CATEGORIES[$obligation->category] ?? $obligation->category,
                'frequency' => Obligation::FREQUENCIES[$obligation->frequency] ?? $obligation->frequency,
                'startsOn' => $obligation->starts_on->toDateString(),
                'endsOn' => $obligation->ends_on->toDateString(),
                'dueDays' => $obligation->due_days,
                'responsible' => Routing::partyName($acting->project, $obligation->responsible),
                'reviewer' => Routing::partyName($acting->project, $obligation->reviewer),
                'status' => $obligation->status,
                'reports' => (int) $obligation->getAttribute('reports_count'),
                'endUrl' => $this->sideUrl($acting, 'reports.obligations.end', ['obligation' => $obligation->id]),
            ])->values(),
            'can' => [
                'create' => $acting->may('projects.reports.prepare', Capability::REPORTS_SUBMIT) && $acting->project->isOpen(),
                'manageObligations' => $acting->isOwner() && $acting->may('projects.reporting.manage') && $acting->project->isOpen(),
            ],
            'categories' => $this->options(Report::CATEGORIES),
            'frequencies' => $this->options(Obligation::FREQUENCIES),
            'reviewers' => $this->reviewers($acting, $acting->participant),
            'defaultReviewer' => Routing::defaultReviewer($acting->participant, Capability::REPORTS_REVIEW)->id ?? 'owner',
            'parties' => $acting->isOwner() ? $this->parties($acting) : [],
            'urls' => [
                'store' => $this->sideUrl($acting, 'reports.store'),
                'obligations' => $acting->isOwner() ? $this->sideUrl($acting, 'reports.obligations.store') : null,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $acting->require('projects.reports.prepare', Capability::REPORTS_SUBMIT);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_keys(Report::CATEGORIES))],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'due_on' => ['nullable', 'date'],
            'reviewer' => ['nullable', 'string', 'max:64'],
        ]);
        $report = Reports::create($acting, [
            'title' => $validated['title'],
            'category' => $validated['category'],
            'period_start' => $validated['period_start'] ?? null,
            'period_end' => $validated['period_end'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
            'reviewer' => $validated['reviewer'] ?? null,
        ]);

        return redirect($this->sideUrl($acting, 'reports.show', ['report' => $report->id]))->with('success', "{$report->reference()} created as a draft.");
    }

    public function storeObligation(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        abort_unless($acting->isOwner() && $acting->may('projects.reporting.manage'), 403);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(array_keys(Report::CATEGORIES))],
            'frequency' => ['required', Rule::in(array_keys(Obligation::FREQUENCIES))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'due_days' => ['required', 'integer', 'min:0', 'max:365'],
            'responsible' => ['nullable', 'string', 'max:64'],
            'reviewer' => ['nullable', 'string', 'max:64'],
            'publish_after_approval' => ['boolean'],
        ]);
        $obligation = Reports::createObligation($acting, [
            'title' => (string) $validated['title'],
            'category' => (string) $validated['category'],
            'frequency' => (string) $validated['frequency'],
            'starts_on' => (string) $validated['starts_on'],
            'ends_on' => (string) $validated['ends_on'],
            'due_days' => (int) $validated['due_days'],
            'responsible' => $validated['responsible'] ?? null,
            'reviewer' => $validated['reviewer'] ?? null,
            'publish_after_approval' => (bool) ($validated['publish_after_approval'] ?? false),
        ]);

        return back()->with('success', "Reporting set up: {$obligation->title}.");
    }

    public function endObligation(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $obligation = Obligation::query()->where('project_id', $acting->project->id)->find((string) $request->route('obligation')) ?? abort(404);
        Reports::endObligation($obligation, $acting);

        return back()->with('success', "{$obligation->title} ended. Reports already written are kept.");
    }

    public function show(Request $request): Response
    {
        $acting = $this->acting($request);
        $report = $this->report($request, $acting);
        $report->load(['currentVersion', 'obligation', 'submitter.organization', 'reviewer.organization']);

        $versions = ReportVersion::query()->where('report_id', $report->id)->orderBy('number')->get();
        $wanted = $request->integer('version');
        $shown = $versions->firstWhere('number', $wanted) ?? $report->currentVersion;
        // The reviewer sees only what was submitted to it.
        if (! Reports::isSubmitter($report, $acting) && $shown !== null && ! $shown->isFrozen()) {
            $shown = $versions->filter(fn (ReportVersion $version) => $version->isFrozen())->last();
        }

        $submitter = Reports::isSubmitter($report, $acting);
        $reviewer = Reports::isReviewer($report, $acting);
        $editable = $submitter && $report->isEditable() && $acting->may('projects.reports.prepare', Capability::REPORTS_SUBMIT);
        $reviews = $acting->may('projects.reports.review', Capability::REPORTS_REVIEW) && $reviewer;
        $ownVersion = $report->currentVersion?->submitted_by === $acting->user->id;

        return Inertia::render('portal/collaboration/report', [
            'frame' => $this->frame($request, $acting),
            'report' => [
                ...$this->row($report, $acting),
                'obligation' => $report->obligation?->title,
                'publishHint' => (bool) $report->obligation?->publish_after_approval,
                'submittedAt' => $report->submitted_at?->toIso8601String(),
                'decidedAt' => $report->decided_at?->toIso8601String(),
                'publishedAt' => $report->published_at?->toIso8601String(),
            ],
            'version' => $shown === null ? null : [
                'number' => $shown->number,
                'frozen' => $shown->isFrozen(),
                'submittedAt' => $shown->submitted_at?->toIso8601String(),
                'content' => $this->content($shown->content, $acting, $report),
                'attachments' => $this->attachments(Attachments::of(Attachment::REPORT_VERSION, $shown->id), $acting, $report),
            ],
            'versions' => $versions->filter(fn (ReportVersion $version) => $submitter || $version->isFrozen())
                ->map(fn (ReportVersion $version) => ['number' => $version->number, 'submittedAt' => $version->submitted_at?->toIso8601String()])->values(),
            'reviews' => $report->reviews()->with(['user:id,name', 'actingOrganization:id,name', 'version:id,number'])->get()
                ->map(fn (ReportReview $review) => [
                    'id' => $review->id,
                    'action' => $review->action,
                    'comment' => $review->comment,
                    'by' => $review->user->name,
                    'organization' => $review->actingOrganization->name,
                    'version' => $review->version?->number,
                    'at' => $review->created_at->toIso8601String(),
                ])->values(),
            'sources' => $report->sources()->with(['submitter.organization', 'currentVersion'])->get()->map(fn (Report $source) => [
                'id' => $source->id,
                'reference' => $source->reference(),
                'title' => $source->title,
                'from' => Routing::partyName($acting->project, $source->submitter),
                'periodStart' => $source->period_start?->toDateString(),
                'periodEnd' => $source->period_end?->toDateString(),
                'status' => $source->status,
                'content' => $source->currentVersion === null ? null : $this->content($source->currentVersion->content, $acting, $source),
                'removeUrl' => $editable ? $this->sideUrl($acting, 'reports.sources.destroy', ['report' => $report->id, 'source' => $source->id]) : null,
            ])->values(),
            'sourceCandidates' => $editable ? Reports::sourceCandidates($report, $acting)->map(fn (Report $candidate) => [
                'value' => $candidate->id,
                'label' => "{$candidate->reference()} · {$candidate->title} (".Routing::partyName($acting->project, $candidate->submitter).')',
            ])->values() : [],
            'can' => [
                'edit' => $editable,
                'submit' => $editable,
                'withdraw' => $submitter && in_array($report->status, Report::IN_REVIEW, true) && $acting->may('projects.reports.prepare', Capability::REPORTS_SUBMIT),
                'start' => $reviews && ! $ownVersion && $report->status === Report::SUBMITTED,
                'decide' => $reviews && ! $ownVersion && in_array($report->status, Report::IN_REVIEW, true),
                'publish' => (($reviewer) || ($acting->isOwner() && Reports::canSee($report, $acting))) && $report->status === Report::APPROVED && $acting->may('projects.reports.publish', Capability::REPORTS_PUBLISH),
                'comment' => ($submitter || $reviewer) && ($acting->may('projects.reports.prepare', Capability::REPORTS_SUBMIT) || $acting->may('projects.reports.review', Capability::REPORTS_REVIEW)),
                'ownVersion' => $ownVersion,
            ],
            'currency' => $acting->project->currency,
            'labels' => ['implementation' => ReportContent::IMPLEMENTATION, 'narrative' => ReportContent::NARRATIVE, 'sources' => ReportContent::SOURCES],
            'urls' => [
                'index' => $this->sideUrl($acting, 'reports.index'),
                'show' => $this->sideUrl($acting, 'reports.show', ['report' => $report->id]),
                'update' => $this->sideUrl($acting, 'reports.update', ['report' => $report->id]),
                'action' => $this->sideUrl($acting, 'reports.action', ['report' => $report->id]),
                'sources' => $this->sideUrl($acting, 'reports.sources.store', ['report' => $report->id]),
                'attachments' => $this->sideUrl($acting, 'reports.attachments.store', ['report' => $report->id]),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $report = $this->report($request, $acting);
        $content = $request->input('content');
        Reports::saveDraft($report, $acting, is_array($content) ? $content : []);

        return back()->with('success', 'Draft saved.');
    }

    public function action(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $report = $this->report($request, $acting);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['submit', 'withdraw', 'start', 'approve', 'changes', 'reject', 'comment', 'publish'])],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);
        $comment = $validated['comment'] ?? null;

        match ($validated['action']) {
            'submit' => Reports::submit($report, $acting),
            'withdraw' => Reports::withdraw($report, $acting, $comment),
            'publish' => Reports::publish($report, $acting),
            default => Reports::decide($report, $acting, $validated['action'], $comment),
        };

        $done = ['submit' => 'Submitted.', 'withdraw' => 'Withdrawn.', 'start' => 'Review started.', 'approve' => 'Approved.', 'changes' => 'Sent back for changes.', 'reject' => 'Rejected.', 'comment' => 'Comment added.', 'publish' => 'Published.'];

        return back()->with('success', $done[$validated['action']]);
    }

    public function addSource(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $report = $this->report($request, $acting);
        $validated = $request->validate(['source' => ['required', 'uuid']]);
        $source = Report::query()->where('project_id', $acting->project->id)->find((string) $validated['source']) ?? abort(404);
        Reports::addSource($report, $source, $acting);

        return back()->with('success', "{$source->reference()} added as a source.");
    }

    public function removeSource(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $report = $this->report($request, $acting);
        $source = Report::query()->where('project_id', $acting->project->id)->find((string) $request->route('source')) ?? abort(404);
        Reports::removeSource($report, $source, $acting);

        return back()->with('success', "{$source->reference()} removed.");
    }

    public function attach(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $report = $this->report($request, $acting);
        $request->validate(['file' => Attachments::RULES]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $report->load('currentVersion');
        Attachments::toReport($report, $acting, $file);

        return back()->with('success', 'File attached.');
    }

    public function download(Request $request): StreamedResponse
    {
        $acting = $this->acting($request);
        $report = $this->report($request, $acting);
        $attachment = Attachment::query()->find((string) $request->route('attachment')) ?? abort(404);
        abort_unless(Attachments::belongsToReport($attachment, $report), 404);
        $version = ReportVersion::query()->find($attachment->attachable_id);
        // A draft's files are the submitter's until submitted.
        abort_unless(Reports::isSubmitter($report, $acting) || $version?->isFrozen(), 404);

        return Storage::disk(Attachments::DISK)->download($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function report(Request $request, Acting $acting): Report
    {
        $report = Report::query()->where('project_id', $acting->project->id)->find((string) $request->route('report'));
        abort_unless($report !== null && Reports::canSee($report, $acting), 404);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Report $report, Acting $acting): array
    {
        $direction = match (true) {
            Reports::isSubmitter($report, $acting) => 'outgoing',
            $acting->is($report->reviewer_participant_id) => 'incoming',
            default => 'other',
        };

        return [
            'id' => $report->id,
            'reference' => $report->reference(),
            'title' => $report->title,
            'category' => $report->categoryLabel(),
            'periodStart' => $report->period_start?->toDateString(),
            'periodEnd' => $report->period_end?->toDateString(),
            'dueOn' => $report->due_on?->toDateString(),
            'status' => $report->status,
            'statusLabel' => Report::STATUSES[$report->status] ?? $report->status,
            'overdue' => $report->isOverdue(),
            'submitter' => Routing::partyName($acting->project, $report->submitter),
            'reviewer' => Routing::partyName($acting->project, $report->reviewer),
            'direction' => $direction,
            'url' => Reports::canSee($report, $acting) ? $this->sideUrl($acting, 'reports.show', ['report' => $report->id]) : null,
        ];
    }

    /**
     * A version's content as this member may read it, decided here and not
     * in the page: the project's recorded budget figures only for those who
     * may see the budget, and the financial section the party reported only
     * for those Reports::seesReportedFinance allows.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function content(array $content, Acting $acting, Report $report): array
    {
        if (isset($content['financial']) && ! Reports::seesReportedFinance($report, $acting)) {
            $content['financial'] = ['lines' => [], 'note' => null];
            $content['financialHidden'] = true;
        }
        if (isset($content['system']) && is_array($content['system']) && ! $acting->seesBudget()) {
            $content['system']['financial'] = null;
            $content['system']['financialHidden'] = true;
        }

        return $content;
    }

    /**
     * @param  Collection<int, Attachment>  $attachments
     * @return list<array<string, mixed>>
     */
    private function attachments(Collection $attachments, Acting $acting, Report $report): array
    {
        return array_values($attachments->map(fn (Attachment $attachment) => [
            'id' => $attachment->id,
            'name' => $attachment->name,
            'size' => $attachment->size,
            'by' => $attachment->uploader->name,
            'url' => $this->sideUrl($acting, 'reports.attachments.show', ['report' => $report->id, 'attachment' => $attachment->id]),
        ])->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function reviewers(Acting $acting, ?Participant $submitter): array
    {
        $options = [['value' => 'owner', 'label' => Routing::partyName($acting->project, null).' (project owner)']];
        foreach (Routing::candidates($acting->project, $submitter, Capability::REPORTS_REVIEW) as $candidate) {
            $options[] = ['value' => $candidate->id, 'label' => "{$candidate->organization->name} ({$candidate->relationshipLabel()})"];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, submits: bool, reviews: bool}>
     */
    private function parties(Acting $acting): array
    {
        $options = [['value' => 'owner', 'label' => Routing::partyName($acting->project, null).' (project owner)', 'submits' => true, 'reviews' => true]];
        $participants = Participant::query()->with('organization')->where('project_id', $acting->project->id)->where('status', Participant::ACTIVE)->orderBy('joined_at')->get();
        foreach ($participants as $participant) {
            $options[] = [
                'value' => $participant->id,
                'label' => "{$participant->organization->name} ({$participant->relationshipLabel()})",
                'submits' => ParticipantAccess::allows($participant, Capability::REPORTS_SUBMIT),
                'reviews' => ParticipantAccess::allows($participant, Capability::REPORTS_REVIEW),
            ];
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private function options(array $labels): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }
}
