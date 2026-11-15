<?php

namespace App\Collaboration;

use App\Collaboration\Models\Invitation;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\Representative;

/**
 * Participations and invitations as the collaboration pages show them, on
 * either side. Names and statuses only: never anything from the other
 * organization's own records.
 */
final class Rows
{
    /**
     * @param  bool  $withRepresentatives  their names (the owner's managers, and the participant itself)
     * @return array<string, mixed>
     */
    public static function participant(Participant $participant, bool $withRepresentatives = false): array
    {
        $effective = ParticipantAccess::effective($participant);

        return [
            'id' => $participant->id,
            'organization' => $participant->organization->name,
            'relationship' => $participant->relationship,
            'relationshipLabel' => $participant->relationshipLabel(),
            'parentId' => $participant->parent_participant_id,
            'parent' => $participant->parent?->organization->name,
            'status' => $participant->status,
            'capabilities' => Capability::normalize($participant->capabilities),
            'effective' => $effective,
            'cutOff' => $participant->isActive() && $effective === [],
            'joinedAt' => $participant->joined_at->toIso8601String(),
            'statusChangedAt' => $participant->status_changed_at?->toIso8601String(),
            'representatives' => $withRepresentatives
                ? $participant->representatives()->with('user:id,name')->get()->map(fn (Representative $representative) => [
                    'id' => $representative->id,
                    'name' => $representative->user->name,
                    'responsibilities' => Responsibility::labels($representative->responsibilities),
                ])->values()->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function invitation(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'invitee' => $invitation->invitedOrganization->name ?? $invitation->contact_name,
            'email' => $invitation->email,
            'onTatook' => $invitation->invited_organization_id !== null,
            'from' => $invitation->invitingOrganization->name,
            'fromParticipantId' => $invitation->inviting_participant_id,
            'inviter' => $invitation->inviter->name,
            'relationship' => $invitation->relationship,
            'relationshipLabel' => $invitation->relationshipLabel(),
            'capabilities' => Capability::labels(Capability::normalize($invitation->capabilities)),
            'message' => $invitation->message,
            'status' => $invitation->status(),
            'createdAt' => $invitation->created_at->toIso8601String(),
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'answeredAt' => ($invitation->accepted_at ?? $invitation->declined_at ?? $invitation->revoked_at)?->toIso8601String(),
            'acceptedBy' => $invitation->acceptedOrganization?->name,
        ];
    }
}
