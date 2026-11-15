<?php

namespace App\Collaboration;

use App\Actions\Budgets;
use App\Audit\Audit;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\ProjectRequest;
use App\Collaboration\Models\RequestDecision;
use App\Models\GrantAllocation;
use App\Models\GrantDisbursement;
use App\Models\Project;
use App\Support\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project requests (ADR-0015 §5): raised by one party, decided by the one it
 * goes to, passed up when the reviewer escalates. Approving one changes
 * nothing by itself: the owner then acts through its own budget, schedule
 * and funding workflows, and the request links to what followed.
 */
final class Requests
{
    /**
     * @param  array{type: string, title: string, justification: string, reviewer: string|null, details: array<array-key, mixed>}  $data
     *
     * @throws ValidationException
     */
    public static function raise(Acting $acting, array $data): ProjectRequest
    {
        $acting->require('projects.requests.raise', Capability::REQUESTS_CREATE);
        self::ensureOpen($acting->project);
        $details = RequestDetails::validate($data['type'], $data['details'], $acting->project->currency);
        $reviewer = Routing::reviewerFor($acting->project, $acting->participant, $data['reviewer'], Capability::REQUESTS_REVIEW);

        return DB::transaction(function () use ($acting, $data, $details, $reviewer) {
            Project::acrossOrganizations()->whereKey($acting->project->id)->lockForUpdate()->first();
            $request = new ProjectRequest;
            $request->forceFill([
                'organization_id' => $acting->project->organization_id,
                'project_id' => $acting->project->id,
                'number' => ((int) ProjectRequest::query()->where('project_id', $acting->project->id)->max('number')) + 1,
                'type' => $data['type'],
                'title' => trim($data['title']),
                'justification' => trim($data['justification']),
                'details' => $details['details'],
                'amount_minor' => $details['amountMinor'],
                'currency' => $details['currency'],
                'submitter_participant_id' => $acting->partyId(),
                'reviewer_participant_id' => $reviewer?->id,
                'status' => ProjectRequest::DRAFT,
                'raised_by' => $acting->user->id,
            ])->save();
            Reports::audit($acting, 'project_request.raised', $request, ['project' => $acting->project->code, 'request' => $request->reference(), 'type' => $request->type, 'reviewer' => Routing::partyName($acting->project, $reviewer)]);

            return $request;
        });
    }

    /**
     * @param  array{title: string, justification: string, reviewer: string|null, details: array<array-key, mixed>}  $data
     *
     * @throws ValidationException
     */
    public static function update(ProjectRequest $request, Acting $acting, array $data): void
    {
        self::authorizeRaiser($request, $acting);
        $details = RequestDetails::validate($request->type, $data['details'], $acting->project->currency);
        $reviewer = Routing::reviewerFor($acting->project, $acting->participant, $data['reviewer'], Capability::REQUESTS_REVIEW);

        DB::transaction(function () use ($request, $acting, $data, $details, $reviewer) {
            $request = self::lock($request);
            self::ensureEditable($request);
            $before = $request->only(['title', 'justification', 'amount_minor', 'currency', 'reviewer_participant_id']);
            $request->forceFill([
                'title' => trim($data['title']),
                'justification' => trim($data['justification']),
                'details' => $details['details'],
                'amount_minor' => $details['amountMinor'],
                'currency' => $details['currency'],
                // Sent back for changes, it returns to whoever asked for them.
                'reviewer_participant_id' => $request->submitted_at === null ? $reviewer?->id : $request->reviewer_participant_id,
            ])->save();
            Audit::change('project_request.updated', $request, $before, $request->only(array_keys($before)), ['request' => $request->reference()], $acting->project->organization_id, $acting->user);
        });
    }

    /**
     * Sends it to the reviewer with a snapshot of what is asked.
     *
     * @throws ValidationException
     */
    public static function submit(ProjectRequest $request, Acting $acting): void
    {
        self::authorizeRaiser($request, $acting);
        self::ensureOpen($acting->project);

        DB::transaction(function () use ($request, $acting) {
            $request = self::lock($request);
            self::ensureEditable($request);

            $rerouted = false;
            if (! Routing::stillReviews($request->reviewer_participant_id, Capability::REQUESTS_REVIEW)) {
                $request->reviewer_participant_id = Routing::defaultReviewer($acting->participant, Capability::REQUESTS_REVIEW)?->id;
                $rerouted = true;
            }

            $again = $request->submitted_at !== null;
            $request->forceFill(['status' => ProjectRequest::SUBMITTED, 'submitted_at' => now(), 'decided_at' => null, 'decided_by' => null])->save();
            self::step($request, $acting, $again ? 'resubmitted' : 'submitted', null, [
                'title' => $request->title,
                'justification' => $request->justification,
                'details' => $request->details,
                'amountMinor' => $request->amount_minor,
                'currency' => $request->currency,
            ]);
            Reports::audit($acting, $again ? 'project_request.resubmitted' : 'project_request.submitted', $request, ['project' => $acting->project->code, 'request' => $request->reference(), 'rerouted' => $rerouted]);
            $request->load('reviewer.organization');
            Notify::party($acting->project, $request->reviewer, 'projects.requests.review', 'requests', $request->id, "{$acting->partyName()} ".($again ? 'resubmitted' : 'submitted')." {$request->reference()}: {$request->typeLabel()}", $request->title, $acting->user, "request-submitted:{$request->id}:".$request->decisions()->count());
        });
    }

    /**
     * Withdrawn by the raising party before a decision.
     */
    public static function cancel(ProjectRequest $request, Acting $acting, ?string $comment): void
    {
        self::authorizeRaiser($request, $acting);

        DB::transaction(function () use ($request, $acting, $comment) {
            $request = self::lock($request);
            if (! in_array($request->status, [...ProjectRequest::EDITABLE, ...ProjectRequest::IN_REVIEW], true)) {
                throw ValidationException::withMessages(['request' => 'This request is '.ProjectRequest::STATUSES[$request->status].'.']);
            }
            $wasSent = $request->submitted_at !== null;
            $request->forceFill(['status' => ProjectRequest::CANCELLED])->save();
            self::step($request, $acting, 'cancelled', self::text($comment));
            Reports::audit($acting, 'project_request.cancelled', $request, ['project' => $acting->project->code, 'request' => $request->reference()]);
            if ($wasSent) {
                Notify::party($acting->project, $request->reviewer, 'projects.requests.review', 'requests', $request->id, "{$acting->partyName()} cancelled {$request->reference()}", $request->title, $acting->user);
            }
        });
    }

    /**
     * The reviewer's decisions, and comments from either party. Nobody
     * decides a request they raised or submitted.
     *
     * @param  'start'|'approve'|'changes'|'reject'|'comment'  $action
     *
     * @throws ValidationException
     */
    public static function decide(ProjectRequest $request, Acting $acting, string $action, ?string $comment): void
    {
        $comment = self::text($comment);
        if ($action === 'comment') {
            self::comment($request, $acting, $comment);

            return;
        }

        abort_unless(self::isReviewer($request, $acting), 403);
        $acting->require('projects.requests.review', Capability::REQUESTS_REVIEW);
        if (in_array($action, ['changes', 'reject'], true) && $comment === null) {
            throw ValidationException::withMessages(['comment' => 'Say why.']);
        }

        DB::transaction(function () use ($request, $acting, $action, $comment) {
            $request = self::lock($request);
            if ($request->raised_by === $acting->user->id || self::lastSubmitter($request) === $acting->user->id) {
                throw ValidationException::withMessages(['request' => 'You raised this request; someone else must decide on it.']);
            }

            [$from, $to, $step] = match ($action) {
                'start' => [[ProjectRequest::SUBMITTED], ProjectRequest::UNDER_REVIEW, 'review_started'],
                'approve' => [ProjectRequest::IN_REVIEW, ProjectRequest::APPROVED, 'approved'],
                'changes' => [ProjectRequest::IN_REVIEW, ProjectRequest::CHANGES_REQUESTED, 'changes_requested'],
                'reject' => [ProjectRequest::IN_REVIEW, ProjectRequest::REJECTED, 'rejected'],
            };
            if (! in_array($request->status, $from, true)) {
                throw ValidationException::withMessages(['request' => 'This request is '.ProjectRequest::STATUSES[$request->status].'.']);
            }

            $decided = $action !== 'start';
            $request->forceFill([
                'status' => $to,
                'decided_at' => $decided ? now() : $request->decided_at,
                'decided_by' => $decided ? $acting->user->id : $request->decided_by,
            ])->save();
            self::step($request, $acting, $step, $comment);
            Reports::audit($acting, "project_request.{$step}", $request, ['project' => $acting->project->code, 'request' => $request->reference(), 'type' => $request->type, 'amount_minor' => $request->amount_minor, 'currency' => $request->currency]);
            if ($decided) {
                $verb = ['approve' => 'approved', 'changes' => 'asked for changes to', 'reject' => 'rejected'][$action];
                Notify::party($acting->project, $request->submitter, 'projects.requests.raise', 'requests', $request->id, "{$acting->partyName()} {$verb} {$request->reference()}", $comment ?? $request->title, $acting->user);
                if ($action === 'approve' && $request->submitter_participant_id !== null && ! $acting->isOwner()) {
                    // The owner acts on what follows; it hears of the approval.
                    Notify::party($acting->project, null, 'projects.manage', 'requests', $request->id, "{$acting->partyName()} approved {$request->reference()}: {$request->typeLabel()}", $request->title, $acting->user);
                }
            }
        });
    }

    /**
     * A reviewing participant passes the request to the party above it,
     * never to one that does not exist, nor back to the one asking.
     *
     * @throws ValidationException
     */
    public static function escalate(ProjectRequest $request, Acting $acting, ?string $comment): void
    {
        abort_unless(self::isReviewer($request, $acting) && $acting->participant !== null, 403);
        $acting->require('projects.requests.review', Capability::REQUESTS_REVIEW);
        $comment = self::text($comment);
        if ($comment === null) {
            throw ValidationException::withMessages(['comment' => 'Say why it goes further.']);
        }

        DB::transaction(function () use ($request, $acting, $comment) {
            $request = self::lock($request);
            if (! in_array($request->status, ProjectRequest::IN_REVIEW, true)) {
                throw ValidationException::withMessages(['request' => 'Only a request waiting for review can be escalated.']);
            }
            /** @var Participant $from */
            $from = $acting->participant;
            $to = Routing::escalation($from, Capability::REQUESTS_REVIEW);
            if ($to?->id === $request->submitter_participant_id) {
                throw ValidationException::withMessages(['request' => 'There is nobody above you to escalate to.']);
            }

            $request->forceFill(['status' => ProjectRequest::SUBMITTED, 'reviewer_participant_id' => $to?->id])->save();
            self::step($request, $acting, 'escalated', $comment, ['from' => $from->id, 'to' => $to?->id]);
            Reports::audit($acting, 'project_request.escalated', $request, ['project' => $acting->project->code, 'request' => $request->reference(), 'to' => Routing::partyName($acting->project, $to)]);
            Notify::party($acting->project, $to, 'projects.requests.review', 'requests', $request->id, "{$acting->partyName()} escalated {$request->reference()}: {$request->typeLabel()}", $comment, $acting->user);
            Notify::party($acting->project, $request->submitter, 'projects.requests.raise', 'requests', $request->id, "{$acting->partyName()} passed {$request->reference()} to ".Routing::partyName($acting->project, $to), $comment, $acting->user);
        });
    }

    /**
     * An approved budget revision opens a draft budget version through the
     * owner's budget workflow; the figures change only when that version is
     * approved there.
     *
     * @throws ValidationException
     */
    public static function startBudgetRevision(ProjectRequest $request, Acting $acting): void
    {
        self::authorizeFollowUp($request, $acting, 'budget_revision', ['projects.manage', 'projects.finance.view']);
        if ($request->budget_version_id !== null) {
            throw ValidationException::withMessages(['request' => 'A budget revision has already been started for this request.']);
        }

        DB::transaction(function () use ($request, $acting) {
            $request = self::lock($request);
            $version = Budgets::revise($acting->project, $acting->user);
            $request->forceFill(['budget_version_id' => $version->id, 'applied_at' => now()])->save();
            self::step($request, $acting, 'budget_revision_started', "Budget version {$version->number} opened as a draft.");
            Reports::audit($acting, 'project_request.budget_revision_started', $request, ['request' => $request->reference(), 'budget_version' => $version->number]);
            ActivityLog::record($acting->project, $acting->user, "Opened budget version {$version->number} for {$request->reference()}");
        });
    }

    /**
     * An approved timeline extension moves the project's end date, as an
     * approved change request does, recorded with what it was.
     *
     * @throws ValidationException
     */
    public static function applyTimeline(ProjectRequest $request, Acting $acting): void
    {
        self::authorizeFollowUp($request, $acting, 'timeline_extension', ['projects.changes.approve']);
        if ($request->applied_at !== null) {
            throw ValidationException::withMessages(['request' => 'The new date has already been applied.']);
        }
        self::ensureOpen($acting->project);

        DB::transaction(function () use ($request, $acting) {
            $request = self::lock($request);
            $project = Project::query()->whereKey($acting->project->id)->lockForUpdate()->firstOrFail();
            $previous = $project->due_on?->toDateString();
            $proposed = (string) ($request->details['proposedEnd'] ?? '');
            $project->forceFill(['due_on' => $proposed])->save();
            $request->forceFill(['applied_at' => now()])->save();
            self::step($request, $acting, 'timeline_applied', 'End date moved from '.($previous ?? 'none')." to {$proposed}.");
            Reports::audit($acting, 'project_request.timeline_applied', $request, ['request' => $request->reference(), 'due_on_from' => $previous, 'due_on_to' => $proposed]);
            ActivityLog::record($project, $acting->user, "Moved the end date to {$proposed} for {$request->reference()}");
        });
    }

    /**
     * Links an approved funding request to a payment scheduled on a grant
     * funding the project. The request shows paid only once that payment is
     * received there; nothing is created or received here.
     *
     * @throws ValidationException
     */
    public static function linkDisbursement(ProjectRequest $request, Acting $acting, string $disbursementId): void
    {
        self::authorizeFollowUp($request, $acting, 'funding', ['grants.manage']);
        $disbursement = GrantDisbursement::query()->find($disbursementId);
        $funds = $disbursement !== null && GrantAllocation::query()->where('grant_id', $disbursement->grant_id)->where('project_id', $acting->project->id)->exists();
        if (! $funds) {
            throw ValidationException::withMessages(['disbursement' => 'Choose a scheduled payment from a grant funding this project.']);
        }
        if (ProjectRequest::query()->where('grant_disbursement_id', $disbursementId)->whereKeyNot($request->id)->exists()) {
            throw ValidationException::withMessages(['disbursement' => 'That payment is already linked to another request.']);
        }

        DB::transaction(function () use ($request, $acting, $disbursement) {
            $request = self::lock($request);
            $request->forceFill(['grant_disbursement_id' => $disbursement->id, 'applied_at' => $request->applied_at ?? now()])->save();
            self::step($request, $acting, 'disbursement_linked', 'Linked to the payment due '.$disbursement->due_on->format('j M Y').'.');
            Reports::audit($acting, 'project_request.disbursement_linked', $request, ['request' => $request->reference(), 'disbursement' => $disbursement->id, 'amount_minor' => $disbursement->amount_minor]);
        });
    }

    /**
     * Where an approved funding request stands. Approval is never money.
     *
     * @return 'not_approved'|'approved_not_scheduled'|'scheduled'|'received'
     */
    public static function fundingState(ProjectRequest $request): string
    {
        if ($request->status !== ProjectRequest::APPROVED) {
            return 'not_approved';
        }
        if ($request->grant_disbursement_id === null) {
            return 'approved_not_scheduled';
        }
        $received = GrantDisbursement::acrossOrganizations()->whereKey($request->grant_disbursement_id)->value('received_on');

        return $received === null ? 'scheduled' : 'received';
    }

    /**
     * The raising party, the reviewing one once submitted, and any it was
     * escalated from.
     */
    public static function canSee(ProjectRequest $request, Acting $acting): bool
    {
        if ($request->project_id !== $acting->project->id) {
            return false;
        }

        if ($acting->isOwner()) {
            // On the owner's side by what the member does with requests:
            // raisers see the owner's own, reviewers also those sent to it.
            // projects.view alone opens none (funding amounts, budget lines).
            return (self::isRaiser($request, $acting) && self::ownerMay($acting, 'projects.requests.raise'))
                || (self::isReviewer($request, $acting) && self::ownerMay($acting, 'projects.requests.review'));
        }

        return $acting->is($request->submitter_participant_id)
            || self::isReviewer($request, $acting)
            || in_array($acting->partyId(), self::escalatedFrom($request), true);
    }

    /**
     * What an owner-side member may follow. "raise": the owner's own
     * requests (raisers, and reviewers). "review": requests sent to the
     * owner (reviewers, and those who carry out an approved one: budget
     * revisions, timeline changes, grant payments).
     */
    private static function ownerMay(Acting $acting, string $side): bool
    {
        $permissions = $side === 'projects.requests.raise'
            ? ['projects.requests.raise', 'projects.requests.review']
            : ['projects.requests.review', 'projects.manage', 'projects.changes.approve', 'grants.manage'];
        foreach ($permissions as $permission) {
            if ($acting->access->has($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Builder<ProjectRequest>
     */
    public static function listed(Acting $acting): Builder
    {
        $query = ProjectRequest::query()->where('project_id', $acting->project->id);
        $id = $acting->partyId();
        if ($acting->isOwner()) {
            $raises = self::ownerMay($acting, 'projects.requests.raise');
            $reviews = self::ownerMay($acting, 'projects.requests.review');
            if (! $raises && ! $reviews) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function (Builder $query) use ($raises, $reviews) {
                if ($raises) {
                    $query->whereNull('submitter_participant_id');
                }
                if ($reviews) {
                    $query->orWhere(fn (Builder $query) => $query->whereNull('reviewer_participant_id')->whereNotNull('submitted_at'));
                }
            });
        }

        return $query->where(function (Builder $query) use ($id, $acting) {
            $id === null ? $query->whereNull('submitter_participant_id') : $query->where('submitter_participant_id', $id);
            $query->orWhere(fn (Builder $query) => ($id === null ? $query->whereNull('reviewer_participant_id') : $query->where('reviewer_participant_id', $id))->whereNotNull('submitted_at'));
            if ($acting->participant !== null) {
                $query->orWhereHas('decisions', fn (Builder $query) => $query->where('action', 'escalated')->where('acting_organization_id', $acting->organizationId()));
            }
        });
    }

    public static function isReviewer(ProjectRequest $request, Acting $acting): bool
    {
        return $request->submitted_at !== null && $acting->is($request->reviewer_participant_id);
    }

    public static function isRaiser(ProjectRequest $request, Acting $acting): bool
    {
        return $acting->is($request->submitter_participant_id);
    }

    /**
     * @return list<string>
     */
    private static function escalatedFrom(ProjectRequest $request): array
    {
        return array_values(array_filter($request->decisions()->where('action', 'escalated')->get()
            ->map(fn (RequestDecision $decision) => $decision->snapshot['from'] ?? null)->all(), 'is_string'));
    }

    private static function lastSubmitter(ProjectRequest $request): ?string
    {
        return RequestDecision::query()->where('request_id', $request->id)->whereIn('action', ['submitted', 'resubmitted'])->latest('created_at')->value('user_id');
    }

    /**
     * @throws ValidationException
     */
    private static function comment(ProjectRequest $request, Acting $acting, ?string $comment): void
    {
        abort_unless(self::isRaiser($request, $acting) || self::isReviewer($request, $acting), 403);
        abort_unless($acting->may('projects.requests.raise', Capability::REQUESTS_CREATE) || $acting->may('projects.requests.review', Capability::REQUESTS_REVIEW), 403);
        if ($comment === null) {
            throw ValidationException::withMessages(['comment' => 'Write a comment.']);
        }

        self::step($request, $acting, 'commented', $comment);
        if ($request->submitted_at === null) {
            return;
        }
        $fromRaiser = self::isRaiser($request, $acting);
        Notify::party($acting->project, $fromRaiser ? $request->reviewer : $request->submitter, $fromRaiser ? 'projects.requests.review' : 'projects.requests.raise', 'requests', $request->id, "{$acting->user->name} commented on {$request->reference()}", $comment, $acting->user);
    }

    private static function authorizeRaiser(ProjectRequest $request, Acting $acting): void
    {
        abort_unless($request->project_id === $acting->project->id && self::isRaiser($request, $acting), 403);
        $acting->require('projects.requests.raise', Capability::REQUESTS_CREATE);
    }

    /**
     * What follows an approval is the owner's, through its own workflows,
     * by members holding what those workflows need.
     *
     * @param  list<string>  $permissions
     *
     * @throws ValidationException
     */
    private static function authorizeFollowUp(ProjectRequest $request, Acting $acting, string $type, array $permissions): void
    {
        abort_unless($acting->isOwner() && $request->project_id === $acting->project->id, 403);
        foreach ($permissions as $permission) {
            $acting->require($permission);
        }
        if ($request->type !== $type || $request->status !== ProjectRequest::APPROVED) {
            throw ValidationException::withMessages(['request' => 'Only an approved '.mb_strtolower(ProjectRequest::TYPES[$type]).' request can be acted on this way.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function ensureEditable(ProjectRequest $request): void
    {
        if (! in_array($request->status, ProjectRequest::EDITABLE, true)) {
            throw ValidationException::withMessages(['request' => 'This request is '.ProjectRequest::STATUSES[$request->status].' and cannot be changed.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function ensureOpen(Project $project): void
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages(['project' => 'This project is closed.']);
        }
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private static function step(ProjectRequest $request, Acting $acting, string $action, ?string $comment, ?array $snapshot = null): void
    {
        $decision = new RequestDecision;
        $decision->forceFill([
            'request_id' => $request->id,
            'action' => $action,
            'comment' => $comment,
            'snapshot' => $snapshot,
            'user_id' => $acting->user->id,
            'acting_organization_id' => $acting->organizationId(),
            'created_at' => now(),
        ])->save();
    }

    private static function lock(ProjectRequest $request): ProjectRequest
    {
        return ProjectRequest::query()->with(['submitter.organization', 'reviewer.organization'])->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 5000);
    }
}
