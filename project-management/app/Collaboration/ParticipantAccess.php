<?php

namespace App\Collaboration;

use App\Access\Access;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\Representative;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What a participation allows, read fresh on every call: nothing here is
 * cached, so suspending or revoking takes effect on the next request
 * (ADR-0014 §3).
 */
final class ParticipantAccess
{
    /** Deeper than any real chain; stops a cycle that should not exist. */
    private const MAX_DEPTH = 16;

    /**
     * Its own capabilities within its parent's, and none unless every
     * participation up the chain is active.
     *
     * @return list<string>
     */
    public static function effective(Participant $participant): array
    {
        $capabilities = null;
        $current = $participant;
        for ($depth = 0; $current !== null; $depth++) {
            if ($depth >= self::MAX_DEPTH || ! $current->isActive()) {
                return [];
            }
            $own = Capability::normalize($current->capabilities);
            $capabilities = $capabilities === null ? $own : array_values(array_intersect($capabilities, $own));
            $current = $current->parent_participant_id === null ? null : Participant::query()->find($current->parent_participant_id);
        }

        return $capabilities;
    }

    public static function allows(Participant $participant, string $capability): bool
    {
        return in_array($capability, self::effective($participant), true);
    }

    public static function isRepresentative(Participant $participant, User $user): bool
    {
        return Representative::query()->where('participant_id', $participant->id)->where('user_id', $user->id)->whereNull('removed_at')->exists();
    }

    /**
     * Whether this member of the participating organization may open the
     * shared project: they represent it there, or manage its collaboration.
     */
    public static function canOpen(Participant $participant, User $user, Access $access): bool
    {
        return $access->organizationId === $participant->participant_organization_id
            && ($access->has('projects.partners.manage') || self::isRepresentative($participant, $user));
    }

    /**
     * Whether this member may invite sub-partners for it: they manage the
     * organization's collaboration and the participation may.
     */
    public static function canInviteFor(Participant $participant, Access $access): bool
    {
        return $access->organizationId === $participant->participant_organization_id
            && $access->has('projects.partners.manage')
            && self::allows($participant, Capability::PARTNERS_MANAGE);
    }

    /**
     * Who on the project a participant may see. One at the top sees every
     * live participation; one under another sees its own chain and those
     * under it.
     *
     * @return Collection<int, Participant>
     */
    public static function visibleTo(Participant $participant): Collection
    {
        $all = Participant::query()->with('organization')->where('project_id', $participant->project_id)
            ->whereIn('status', Participant::LIVE)->orderBy('joined_at')->orderBy('id')->get();
        if ($participant->parent_participant_id === null) {
            return $all->values();
        }

        $visible = [$participant->id => true];
        $byId = $all->keyBy('id');
        for ($current = $participant, $depth = 0; $current->parent_participant_id !== null && $depth < self::MAX_DEPTH; $depth++) {
            $visible[$current->parent_participant_id] = true;
            $parent = $byId->get($current->parent_participant_id);
            if (! $parent instanceof Participant) {
                break;
            }
            $current = $parent;
        }

        return $all->filter(fn (Participant $other) => isset($visible[$other->id]) || self::descendsFrom($other, $participant, $byId))->values();
    }

    /**
     * @param  Collection<string, Participant>  $byId
     */
    private static function descendsFrom(Participant $other, Participant $ancestor, Collection $byId): bool
    {
        for ($current = $other, $depth = 0; $current->parent_participant_id !== null && $depth < self::MAX_DEPTH; $depth++) {
            if ($current->parent_participant_id === $ancestor->id) {
                return true;
            }
            $parent = $byId->get($current->parent_participant_id);
            if (! $parent instanceof Participant) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }
}
