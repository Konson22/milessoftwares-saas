<?php

namespace App\Http\Controllers\Collaboration;

use App\Collaboration\Acting;
use App\Collaboration\Attachments;
use App\Collaboration\Capability;
use App\Collaboration\Models\Attachment;
use App\Collaboration\Models\ProjectRequest;
use App\Collaboration\Models\RequestDecision;
use App\Collaboration\Requests;
use App\Collaboration\Routing;
use App\Enums\CostCategory;
use App\Http\Controllers\Collaboration\Concerns\ActsOnProject;
use App\Http\Controllers\Controller;
use App\Models\Grant;
use App\Models\GrantAllocation;
use App\Models\GrantDisbursement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Project requests (ADR-0015 §5), for the owner and for each participant,
 * on the same pages. What each may see and do is decided in
 * App\Collaboration\Requests; what follows an approval is the owner's.
 */
class RequestController extends Controller
{
    use ActsOnProject;

    public function index(Request $request): Response
    {
        $acting = $this->acting($request);
        $requests = Requests::listed($acting)->with(['submitter.organization', 'reviewer.organization'])->orderByDesc('number')->get();

        return Inertia::render('portal/collaboration/requests', [
            'frame' => $this->frame($request, $acting),
            'requests' => $requests->map(fn (ProjectRequest $projectRequest) => $this->row($projectRequest, $acting))->values(),
            'can' => ['create' => $acting->may('projects.requests.raise', Capability::REQUESTS_CREATE) && $acting->project->isOpen()],
            'types' => array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(ProjectRequest::TYPES), ProjectRequest::TYPES),
            'categories' => array_map(fn (CostCategory $category) => ['value' => $category->value, 'label' => $category->label()], CostCategory::cases()),
            'reviewers' => $this->reviewers($acting),
            'defaultReviewer' => Routing::defaultReviewer($acting->participant, Capability::REQUESTS_REVIEW)->id ?? 'owner',
            'currency' => $acting->project->currency,
            'projectDueOn' => $acting->project->due_on?->toDateString(),
            'urls' => ['store' => $this->sideUrl($acting, 'requests.store')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $acting->require('projects.requests.raise', Capability::REQUESTS_CREATE);
        $validated = $this->validated($request, true);
        $projectRequest = Requests::raise($acting, $validated);

        return redirect($this->sideUrl($acting, 'requests.show', ['projectRequest' => $projectRequest->id]))->with('success', "{$projectRequest->reference()} saved as a draft.");
    }

    public function show(Request $request): Response
    {
        $acting = $this->acting($request);
        $projectRequest = $this->projectRequest($request, $acting);
        $projectRequest->load(['submitter.organization', 'reviewer.organization', 'raiser:id,name']);

        $raiser = Requests::isRaiser($projectRequest, $acting);
        $reviewer = Requests::isReviewer($projectRequest, $acting);
        $reviews = $reviewer && $acting->may('projects.requests.review', Capability::REQUESTS_REVIEW);
        $own = $projectRequest->raised_by === $acting->user->id;
        $approved = $projectRequest->status === ProjectRequest::APPROVED && $acting->isOwner();

        return Inertia::render('portal/collaboration/request', [
            'frame' => $this->frame($request, $acting),
            'request' => [
                ...$this->row($projectRequest, $acting),
                'justification' => $projectRequest->justification,
                'details' => $projectRequest->details,
                'amountMinor' => $projectRequest->amount_minor,
                'currency' => $projectRequest->currency,
                'raisedBy' => $projectRequest->raiser->name,
                'reviewerId' => $projectRequest->reviewer_participant_id ?? 'owner',
                'submittedAt' => $projectRequest->submitted_at?->toIso8601String(),
                'decidedAt' => $projectRequest->decided_at?->toIso8601String(),
                'appliedAt' => $projectRequest->applied_at?->toIso8601String(),
                'fundingState' => $projectRequest->type === 'funding' ? Requests::fundingState($projectRequest) : null,
                'budgetVersion' => $projectRequest->budget_version_id === null ? null : $projectRequest->budgetVersion?->number,
            ],
            'decisions' => $projectRequest->decisions()->with(['user:id,name', 'actingOrganization:id,name'])->get()->map(fn (RequestDecision $decision) => [
                'id' => $decision->id,
                'action' => $decision->action,
                'comment' => $decision->comment,
                'by' => $decision->user->name,
                'organization' => $decision->actingOrganization->name,
                'at' => $decision->created_at->toIso8601String(),
            ])->values(),
            'attachments' => array_values(Attachments::of(Attachment::REQUEST, $projectRequest->id)->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'size' => $attachment->size,
                'by' => $attachment->uploader->name,
                'url' => $this->sideUrl($acting, 'requests.attachments.show', ['projectRequest' => $projectRequest->id, 'attachment' => $attachment->id]),
            ])->all()),
            'can' => [
                'edit' => $raiser && in_array($projectRequest->status, ProjectRequest::EDITABLE, true) && $acting->may('projects.requests.raise', Capability::REQUESTS_CREATE),
                'cancel' => $raiser && in_array($projectRequest->status, [...ProjectRequest::EDITABLE, ...ProjectRequest::IN_REVIEW], true) && $acting->may('projects.requests.raise', Capability::REQUESTS_CREATE),
                'start' => $reviews && ! $own && $projectRequest->status === ProjectRequest::SUBMITTED,
                'decide' => $reviews && ! $own && in_array($projectRequest->status, ProjectRequest::IN_REVIEW, true),
                'escalate' => $reviews && ! $acting->isOwner() && in_array($projectRequest->status, ProjectRequest::IN_REVIEW, true),
                'comment' => $raiser || $reviewer,
                'own' => $own,
                'startBudget' => $approved && $projectRequest->type === 'budget_revision' && $projectRequest->budget_version_id === null && $acting->may('projects.manage') && $acting->may('projects.finance.view'),
                'applyTimeline' => $approved && $projectRequest->type === 'timeline_extension' && $projectRequest->applied_at === null && $acting->may('projects.changes.approve'),
                'linkDisbursement' => $approved && $projectRequest->type === 'funding' && $acting->may('grants.manage'),
            ],
            'disbursements' => $approved && $projectRequest->type === 'funding' && $acting->may('grants.manage') ? $this->disbursements($acting) : [],
            'types' => ProjectRequest::TYPES,
            'categories' => array_map(fn (CostCategory $category) => ['value' => $category->value, 'label' => $category->label()], CostCategory::cases()),
            'reviewers' => $this->reviewers($acting),
            'urls' => [
                'index' => $this->sideUrl($acting, 'requests.index'),
                'update' => $this->sideUrl($acting, 'requests.update', ['projectRequest' => $projectRequest->id]),
                'action' => $this->sideUrl($acting, 'requests.action', ['projectRequest' => $projectRequest->id]),
                'followUp' => $acting->isOwner() ? $this->sideUrl($acting, 'requests.follow-up', ['projectRequest' => $projectRequest->id]) : null,
                'attachments' => $this->sideUrl($acting, 'requests.attachments.store', ['projectRequest' => $projectRequest->id]),
                'budget' => $acting->isOwner() && $projectRequest->budget_version_id !== null ? route('projects.budget', ['project' => $acting->project->id]) : null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $projectRequest = $this->projectRequest($request, $acting);
        $validated = $this->validated($request, false);
        Requests::update($projectRequest, $acting, $validated);

        return back()->with('success', 'Saved.');
    }

    public function action(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $projectRequest = $this->projectRequest($request, $acting);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['submit', 'cancel', 'start', 'approve', 'changes', 'reject', 'comment', 'escalate'])],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);
        $comment = $validated['comment'] ?? null;

        match ($validated['action']) {
            'submit' => Requests::submit($projectRequest, $acting),
            'cancel' => Requests::cancel($projectRequest, $acting, $comment),
            'escalate' => Requests::escalate($projectRequest, $acting, $comment),
            default => Requests::decide($projectRequest, $acting, $validated['action'], $comment),
        };

        $done = ['submit' => 'Submitted.', 'cancel' => 'Cancelled.', 'start' => 'Review started.', 'approve' => 'Approved.', 'changes' => 'Sent back for changes.', 'reject' => 'Rejected.', 'comment' => 'Comment added.', 'escalate' => 'Escalated.'];

        return back()->with('success', $done[$validated['action']]);
    }

    /**
     * What the owner does after approving, through its own workflows.
     */
    public function followUp(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $projectRequest = $this->projectRequest($request, $acting);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['budget', 'timeline', 'disbursement'])],
            'disbursement' => ['required_if:action,disbursement', 'nullable', 'uuid'],
        ]);

        match ($validated['action']) {
            'budget' => Requests::startBudgetRevision($projectRequest, $acting),
            'timeline' => Requests::applyTimeline($projectRequest, $acting),
            'disbursement' => Requests::linkDisbursement($projectRequest, $acting, (string) $validated['disbursement']),
            default => abort(422),
        };

        $done = ['budget' => 'A draft budget version is open. Change it, then submit it for approval as usual.', 'timeline' => 'The new end date is applied.', 'disbursement' => 'Linked to the scheduled payment. It counts as received only once recorded there.'];

        return back()->with('success', $done[$validated['action']]);
    }

    public function attach(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $projectRequest = $this->projectRequest($request, $acting);
        $request->validate(['file' => Attachments::RULES]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        Attachments::toRequest($projectRequest, $acting, $file);

        return back()->with('success', 'File attached.');
    }

    public function download(Request $request): StreamedResponse
    {
        $acting = $this->acting($request);
        $projectRequest = $this->projectRequest($request, $acting);
        $attachment = Attachment::query()->find((string) $request->route('attachment')) ?? abort(404);
        abort_unless(Attachments::belongsToRequest($attachment, $projectRequest), 404);

        return Storage::disk(Attachments::DISK)->download($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @return array{type: string, title: string, justification: string, reviewer: string|null, details: array<array-key, mixed>}
     */
    private function validated(Request $request, bool $withType): array
    {
        $validated = $request->validate([
            'type' => [$withType ? 'required' : 'prohibited', Rule::in(array_keys(ProjectRequest::TYPES))],
            'title' => ['required', 'string', 'max:200'],
            'justification' => ['required', 'string', 'max:5000'],
            'reviewer' => ['nullable', 'string', 'max:64'],
            'details' => ['required', 'array'],
        ]);

        return [
            'type' => (string) ($validated['type'] ?? ''),
            'title' => $validated['title'],
            'justification' => $validated['justification'],
            'reviewer' => $validated['reviewer'] ?? null,
            'details' => $validated['details'],
        ];
    }

    private function projectRequest(Request $request, Acting $acting): ProjectRequest
    {
        $projectRequest = ProjectRequest::query()->where('project_id', $acting->project->id)->find((string) $request->route('projectRequest'));
        abort_unless($projectRequest !== null && Requests::canSee($projectRequest, $acting), 404);

        return $projectRequest;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ProjectRequest $projectRequest, Acting $acting): array
    {
        return [
            'id' => $projectRequest->id,
            'reference' => $projectRequest->reference(),
            'type' => $projectRequest->type,
            'typeLabel' => $projectRequest->typeLabel(),
            'title' => $projectRequest->title,
            'status' => $projectRequest->status,
            'statusLabel' => ProjectRequest::STATUSES[$projectRequest->status] ?? $projectRequest->status,
            'amountMinor' => $projectRequest->amount_minor,
            'currency' => $projectRequest->currency,
            'submitter' => Routing::partyName($acting->project, $projectRequest->submitter),
            'reviewer' => Routing::partyName($acting->project, $projectRequest->reviewer),
            'direction' => Requests::isRaiser($projectRequest, $acting) ? 'outgoing' : ($acting->is($projectRequest->reviewer_participant_id) ? 'incoming' : 'other'),
            'createdAt' => $projectRequest->created_at->toIso8601String(),
            'url' => $this->sideUrl($acting, 'requests.show', ['projectRequest' => $projectRequest->id]),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function reviewers(Acting $acting): array
    {
        $options = [['value' => 'owner', 'label' => Routing::partyName($acting->project, null).' (project owner)']];
        foreach (Routing::candidates($acting->project, $acting->participant, Capability::REQUESTS_REVIEW) as $candidate) {
            $options[] = ['value' => $candidate->id, 'label' => "{$candidate->organization->name} ({$candidate->relationshipLabel()})"];
        }

        return $options;
    }

    /**
     * Payments scheduled on grants funding the project, not yet linked.
     *
     * @return list<array{value: string, label: string}>
     */
    private function disbursements(Acting $acting): array
    {
        $grants = GrantAllocation::query()->where('project_id', $acting->project->id)->pluck('grant_id');
        $linked = ProjectRequest::query()->whereNotNull('grant_disbursement_id')->pluck('grant_disbursement_id')->all();

        $references = Grant::query()->whereIn('id', $grants)->pluck('reference', 'id');

        return array_values(GrantDisbursement::query()->whereIn('grant_id', $grants)->whereNotIn('id', $linked)->orderBy('due_on')->get()
            ->map(fn (GrantDisbursement $disbursement) => [
                'value' => $disbursement->id,
                'label' => ($references[$disbursement->grant_id] ?? 'Grant').": due {$disbursement->due_on->format('j M Y')}".($disbursement->received_on !== null ? ' (received)' : ''),
                'amountMinor' => $disbursement->amount_minor,
            ])->all());
    }
}
