<?php

namespace App\Http\Controllers\Collaboration;

use App\Access\Access;
use App\Access\CurrentAccess;
use App\Collaboration\Capability;
use App\Collaboration\Collaboration;
use App\Collaboration\Models\Invitation;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\Representative;
use App\Collaboration\ParticipantAccess;
use App\Collaboration\ParticipantProjection;
use App\Collaboration\Relationship;
use App\Collaboration\Responsibility;
use App\Collaboration\Rows;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\CollaborationController;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Projects of other organizations this one takes part in, in its own
 * workspace (ADR-0014): the invitations waiting for it, the projects, who
 * works on each for it, and sub-partners it brings in. The owner's project
 * is read only through App\Collaboration\ParticipantProjection. Every page
 * re-reads the participation, so a suspended or revoked one shows nothing,
 * and none is kept by a browser or proxy.
 */
class SharedProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->member($request);
        $organization = app(CurrentOrganization::class)->require();
        $manage = $this->access()->has('projects.partners.manage');

        $participations = Participant::query()->with(['owner', 'organization', 'parent.organization'])
            ->where('participant_organization_id', $organization->id)
            ->when(! $manage, fn ($query) => $query->whereIn('status', Participant::LIVE)
                ->whereHas('representatives', fn ($represents) => $represents->where('user_id', $user->id)))
            ->orderByDesc('joined_at')->get();

        $waiting = $manage
            ? Invitation::query()->with(['project', 'invitingOrganization', 'inviter', 'invitedOrganization', 'acceptedOrganization'])
                ->where('invited_organization_id', $organization->id)->open()->latest()->get()
            : collect();

        return $this->private(Inertia::render('portal/shared-projects/index', [
            'invitations' => $waiting->map(fn (Invitation $invitation) => [...Rows::invitation($invitation), 'project' => $invitation->project->name])->values(),
            'projects' => $participations->map(fn (Participant $participant) => [
                ...Rows::participant($participant),
                'project' => $participant->project->name,
                'code' => $participant->project->code,
                'owner' => $participant->owner->name,
                'url' => route('shared-projects.show', ['participant' => $participant->id], false),
            ])->values(),
            'canManage' => $manage,
        ]), $request);
    }

    public function show(Request $request, Participant $participant): Response
    {
        $access = $this->access();
        $user = $this->member($request);
        $this->authorizeOpen($participant, $user, $access);

        $effective = ParticipantAccess::effective($participant);
        $manage = $access->has('projects.partners.manage');
        $canInvite = ParticipantAccess::canInviteFor($participant, $access);
        $children = Participant::query()->with(['organization', 'parent.organization'])->where('parent_participant_id', $participant->id)->orderBy('joined_at')->get();

        return $this->private(Inertia::render('portal/shared-projects/show', [
            'participation' => Rows::participant($participant->load(['organization', 'parent.organization']), withRepresentatives: true),
            'capabilityLabels' => Capability::labels($effective),
            // Nothing of the project while the participation, or one above it, is not active.
            'project' => $effective === [] ? null : ParticipantProjection::project($participant, $effective),
            'participants' => $effective === [] ? [] : ParticipantAccess::visibleTo($participant)
                ->map(fn (Participant $other) => [
                    'id' => $other->id,
                    'organization' => $other->organization->name,
                    'relationshipLabel' => $other->relationshipLabel(),
                    'status' => $other->status,
                    'isUs' => $other->id === $participant->id,
                ])->values(),
            'canManage' => $manage && $participant->isLive(),
            'members' => $manage ? $this->memberOptions($participant) : [],
            'responsibilities' => Responsibility::options(),
            'canInvite' => $canInvite,
            'subPartners' => $canInvite || $manage ? $children->map(fn (Participant $child) => Rows::participant($child))->values() : [],
            'sentInvitations' => $manage ? Invitation::query()->with(['invitedOrganization', 'invitingOrganization', 'inviter', 'acceptedOrganization'])
                ->where('inviting_participant_id', $participant->id)->latest()->get()
                ->map(fn (Invitation $invitation) => Rows::invitation($invitation))->values() : [],
            'grantable' => $canInvite ? $effective : [],
            'capabilities' => Capability::options(),
            'relationships' => Relationship::options(),
        ]), $request);
    }

    /**
     * A direct invitation, to this organization. An emailed one opens only
     * through its link (InvitationLinkController), never by its id.
     */
    public function accept(Request $request, Invitation $invitation): RedirectResponse
    {
        abort_if($invitation->invited_organization_id === null, 404);
        $participant = Collaboration::accept($invitation, app(CurrentOrganization::class)->require(), $this->member($request), $this->access());

        return to_route('shared-projects.show', ['participant' => $participant->id])->with('success', "You now take part in {$participant->project->name}.");
    }

    public function decline(Request $request, Invitation $invitation): RedirectResponse
    {
        abort_if($invitation->invited_organization_id === null, 404);
        Collaboration::decline($invitation, app(CurrentOrganization::class)->require(), $this->member($request), $this->access());

        return back()->with('success', 'Invitation declined.');
    }

    public function addRepresentative(Request $request, Participant $participant): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
            'responsibilities' => ['nullable', 'array'],
            'responsibilities.*' => ['string', Rule::in(array_keys(Responsibility::LABELS))],
        ]);
        $member = User::query()->whereKey($validated['user_id'])->first();
        abort_if($member === null, 422);

        Collaboration::addRepresentative($participant, $member, $validated['responsibilities'] ?? [], $this->member($request), $this->access());

        return back()->with('success', "{$member->name} works on this project now.");
    }

    public function removeRepresentative(Request $request, Participant $participant, Representative $representative): RedirectResponse
    {
        abort_unless($representative->participant_id === $participant->id, 404);
        Collaboration::removeRepresentative($representative, $this->member($request), $this->access());

        return back()->with('success', "{$representative->user->name} no longer works on this project.");
    }

    public function invite(Request $request, Participant $participant): RedirectResponse
    {
        $validated = CollaborationController::validateInvitation($request, withParent: false);
        $invitation = Collaboration::invite($participant->project, $this->member($request), $this->access(), $participant, $validated);

        return back()->with('success', 'Invitation sent to '.($invitation->invitedOrganization->name ?? $invitation->email).'.');
    }

    public function revoke(Request $request, Participant $participant, Invitation $invitation): RedirectResponse
    {
        abort_unless($invitation->inviting_participant_id === $participant->id, 404);
        Collaboration::revoke($invitation, $this->member($request), $this->access());

        return back()->with('success', 'Invitation withdrawn.');
    }

    public function resend(Request $request, Participant $participant, Invitation $invitation): RedirectResponse
    {
        abort_unless($invitation->inviting_participant_id === $participant->id, 404);
        Collaboration::resend($invitation, $this->member($request), $this->access());

        return back()->with('success', 'Invitation sent again.');
    }

    /**
     * A sub-partner it brought in: what it may do, or its status.
     */
    public function updateSubPartner(Request $request, Participant $participant, Participant $child): RedirectResponse
    {
        abort_unless($child->parent_participant_id === $participant->id, 404);
        $validated = $request->validate([
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in(Capability::keys())],
            'status' => ['nullable', Rule::in([Participant::ACTIVE, Participant::SUSPENDED, Participant::COMPLETED, Participant::REVOKED])],
        ]);

        if (isset($validated['status'])) {
            Collaboration::setStatus($child, $validated['status'], $this->member($request), $this->access(), $participant);
        }
        if (isset($validated['capabilities'])) {
            Collaboration::changeCapabilities($child, $validated['capabilities'], $this->member($request), $this->access(), $participant);
        }

        return back()->with('success', "{$child->organization->name} is updated.");
    }

    /**
     * Its own: the organization's collaboration managers, or someone who
     * works on it for the organization. Anything else looks unknown.
     */
    private function authorizeOpen(Participant $participant, User $user, Access $access): void
    {
        abort_unless(ParticipantAccess::canOpen($participant, $user, $access), 404);
        abort_if(in_array($participant->status, Participant::ENDED, true) && ! $access->has('projects.partners.manage'), 404);
    }

    /**
     * Active members of the organization not yet working on it.
     *
     * @return list<array{value: string, label: string}>
     */
    private function memberOptions(Participant $participant): array
    {
        $taken = $participant->representatives()->pluck('user_id')->all();

        return array_values(OrganizationMembership::query()->with('user:id,name')
            ->where('organization_id', $participant->participant_organization_id)
            ->where('status', OrganizationMembership::ACTIVE)->whereNotIn('user_id', $taken)->get()
            ->map(fn (OrganizationMembership $membership) => ['value' => $membership->user_id, 'label' => $membership->user->name])
            ->sortBy('label')->values()->all());
    }

    /**
     * Shared pages are never kept: access is re-read on each request.
     */
    private function private(\Inertia\Response $page, Request $request): Response
    {
        $response = $page->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function access(): Access
    {
        return app(CurrentAccess::class)->get() ?? abort(403);
    }
}
