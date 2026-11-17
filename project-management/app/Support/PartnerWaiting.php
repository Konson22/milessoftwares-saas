<?php

namespace App\Support;

use App\Collaboration\Models\Participant;
use App\Models\Grant;
use App\Models\GrantDisbursement;
use App\Models\Task;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;

/**
 * What waits on this organization from the ones it works with (Phase 5): on
 * the home page of an implementer. Each item is what the member may act on:
 *
 * - partner work to review: tasks given to a partner, sent for review
 *   (projects.tasks.manage);
 * - tasks given to it on another organization's project, still open
 *   (projects.tasks.manage, through an active participation);
 * - grant payments the donor recorded as sent, to confirm or dispute, and
 *   terms a donor declined (grants.manage).
 *
 * Reads only: rows of another organization are read inside it, by the
 * participation that entitles this one to them.
 */
/**
 * @phpstan-type Item array{key: string, kind: string, title: string, detail: string, dueOn: string|null, url: string}
 */
final class PartnerWaiting
{
    private const LIMIT = 8;

    /**
     * @return array{count: int, items: list<Item>}
     */
    public static function for(User $user): array
    {
        $organization = app(CurrentOrganization::class)->require();
        /** @var Collection<int, Item> $items */
        $items = collect();

        if ($user->can('projects.tasks.manage')) {
            $items = $items->merge(self::toReview())->merge(self::givenToUs($organization->id));
        }
        if ($user->can('grants.manage')) {
            $items = $items->merge(self::grants());
        }
        $items = $items->sortBy(fn (array $item) => $item['dueOn'] ?? '9999-12-31')->values();

        /** @var list<Item> $first */
        $first = $items->take(self::LIMIT)->values()->all();

        return ['count' => $items->count(), 'items' => $first];
    }

    /**
     * @return Collection<int, Item>
     */
    private static function toReview(): Collection
    {
        return Task::query()->with(['project:id,code', 'participant.organization:id,name'])
            ->whereNotNull('participant_id')->where('status', Task::REVIEW)->whereNull('parent_id')
            ->get()->map(fn (Task $task) => [
                'key' => "review-{$task->id}",
                'kind' => 'review',
                'title' => "{$task->number} · {$task->title}",
                'detail' => ($task->participant?->organization->name ?? 'A partner').' sent it for review',
                'dueOn' => $task->due_on?->toDateString(),
                'url' => route('projects.shared-tasks.show', ['project' => $task->project_id, 'task' => $task->id], false),
            ]);
    }

    /**
     * @return Collection<int, Item>
     */
    private static function givenToUs(string $organizationId): Collection
    {
        $participations = Participant::query()->with(['owner', 'project:id,code,name'])
            ->where('participant_organization_id', $organizationId)->where('status', Participant::ACTIVE)->get();

        return $participations->flatMap(function (Participant $participant) {
            $owner = $participant->owner;
            if (! $owner->isActive()) {
                return [];
            }

            return app(CurrentOrganization::class)->run($owner, fn () => Task::query()
                ->where('participant_id', $participant->id)->whereNull('parent_id')
                ->whereIn('status', [Task::TODO, Task::IN_PROGRESS])
                ->orderByRaw('due_on is null')->orderBy('due_on')->limit(self::LIMIT)->get()
                ->map(fn (Task $task) => [
                    'key' => "given-{$task->id}",
                    'kind' => 'given',
                    'title' => "{$task->number} · {$task->title}",
                    'detail' => "{$owner->name}'s project, given to you",
                    'dueOn' => $task->due_on?->toDateString(),
                    'url' => route('shared-projects.shared-tasks.show', ['participant' => $participant->id, 'task' => $task->id], false),
                ])->all());
        });
    }

    /**
     * @return Collection<int, Item>
     */
    private static function grants(): Collection
    {
        $sent = GrantDisbursement::query()->where('status', 'sent')->get();
        $grants = Grant::query()->where(fn ($query) => $query->whereIn('id', $sent->pluck('grant_id'))->orWhere('agreement_status', 'declined'))->get()->keyBy('id');

        return $sent->map(fn (GrantDisbursement $payment) => [
            'key' => "sent-{$payment->id}",
            'kind' => 'confirm',
            'title' => 'Confirm a grant payment: '.Money::format($payment->amount_minor, $grants[$payment->grant_id]->currency),
            'detail' => $grants[$payment->grant_id]->reference.', sent '.$payment->sent_on?->toDateString(),
            'dueOn' => $payment->due_on->toDateString(),
            'url' => route('grants.show', ['grant' => $payment->grant_id], false),
        ])->merge($grants->where('agreement_status', 'declined')->map(fn (Grant $grant) => [
            'key' => "declined-{$grant->id}",
            'kind' => 'declined',
            'title' => "Terms of {$grant->reference} declined",
            'detail' => $grant->agreement_note ?? 'The donor declined the terms proposed.',
            'dueOn' => null,
            'url' => route('grants.show', ['grant' => $grant->id], false),
        ])->values());
    }
}
