<?php

namespace App\Collaboration;

use App\Collaboration\Models\Participant;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Who a report or request goes to (ADR-0015 §2), from the participations
 * as they stand: never a fixed donor, lead and sub-partner chain, and never
 * an intermediary that does not exist. The owner is null.
 */
final class Routing
{
    private const MAX_DEPTH = 16;

    /**
     * The first active participation above the submitter that may review;
     * otherwise the owner. The owner, submitting, reviews internally.
     */
    public static function defaultReviewer(?Participant $submitter, string $capability): ?Participant
    {
        if ($submitter === null) {
            return null;
        }

        $current = $submitter;
        for ($depth = 0; $current->parent_participant_id !== null && $depth < self::MAX_DEPTH; $depth++) {
            $parent = Participant::query()->find($current->parent_participant_id);
            if ($parent === null) {
                break;
            }
            if (ParticipantAccess::allows($parent, $capability)) {
                return $parent;
            }
            $current = $parent;
        }

        return null;
    }

    /**
     * Who the submitter may send to: the owner, or an active participation
     * that may review, never itself nor one under it.
     *
     * @return Collection<int, Participant>
     */
    public static function candidates(Project $project, ?Participant $submitter, string $capability): Collection
    {
        return Participant::query()->with('organization')
            ->where('project_id', $project->id)->where('status', Participant::ACTIVE)->orderBy('joined_at')->get()
            ->filter(fn (Participant $participant) => $participant->id !== $submitter?->id
                && ! ($submitter !== null && self::isUnder($participant, $submitter))
                && ParticipantAccess::allows($participant, $capability))
            ->values();
    }

    /**
     * Checks a chosen reviewer, or picks the default when none is chosen.
     * '' or 'owner' means the owner.
     *
     * @throws ValidationException
     */
    public static function reviewerFor(Project $project, ?Participant $submitter, ?string $chosen, string $capability, string $field = 'reviewer'): ?Participant
    {
        if ($chosen === null) {
            return self::defaultReviewer($submitter, $capability);
        }
        if ($chosen === '' || $chosen === 'owner') {
            return null;
        }

        $reviewer = self::candidates($project, $submitter, $capability)->firstWhere('id', $chosen);
        if (! $reviewer instanceof Participant) {
            throw ValidationException::withMessages([$field => 'Choose the owner or an organization on this project that may review.']);
        }

        return $reviewer;
    }

    /**
     * Whether a reviewer named earlier may still review: the owner always,
     * a participation while active and allowed.
     */
    public static function stillReviews(?string $reviewerId, string $capability): bool
    {
        if ($reviewerId === null) {
            return true;
        }
        $reviewer = Participant::query()->find($reviewerId);

        return $reviewer !== null && ParticipantAccess::allows($reviewer, $capability);
    }

    /**
     * Where a reviewing participant passes something up: the first active
     * participation above it that may review, otherwise the owner.
     */
    public static function escalation(Participant $reviewer, string $capability): ?Participant
    {
        return self::defaultReviewer($reviewer, $capability);
    }

    public static function partyName(Project $project, ?Participant $participant): string
    {
        if ($participant !== null) {
            return $participant->organization->name;
        }

        return Organization::query()->whereKey($project->organization_id)->value('name') ?? 'Project owner';
    }

    /**
     * Whether $participant sits somewhere under $ancestor.
     */
    public static function isUnder(Participant $participant, Participant $ancestor): bool
    {
        $current = $participant;
        for ($depth = 0; $current->parent_participant_id !== null && $depth < self::MAX_DEPTH; $depth++) {
            if ($current->parent_participant_id === $ancestor->id) {
                return true;
            }
            $parent = Participant::query()->find($current->parent_participant_id);
            if ($parent === null) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }
}
