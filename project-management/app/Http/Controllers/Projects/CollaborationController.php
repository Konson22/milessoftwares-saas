<?php

namespace App\Http\Controllers\Projects;

use App\Access\Access;
use App\Access\CurrentAccess;
use App\Collaboration\Capability;
use App\Collaboration\Collaboration;
use App\Collaboration\Models\Invitation;
use App\Collaboration\Models\Participant;
use App\Collaboration\Relationship;
use App\Collaboration\Rows;
use App\Http\Controllers\Concerns\ShowsProject;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Models\Project;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organizations taking part in a project the organization owns, the
 * invitations it has sent, and what each may do there (ADR-0014). Anyone
 * who sees the project sees who takes part; changing it needs
 * projects.partners.manage, checked again in App\Collaboration\Collaboration.
 */
class CollaborationController extends Controller
{
    use ShowsProject;

    public function show(Request $request, Project $project): Response
    {
        $manage = $this->can($request, 'projects.partners.manage');
        $participants = $project->participants()->with(['organization', 'parent.organization'])->orderBy('joined_at')->get();

        return Inertia::render('portal/projects/collaboration', [
            'project' => $this->projectHeader($request, $project),
            'owner' => app(CurrentOrganization::class)->require()->name,
            'participants' => $participants->map(fn (Participant $participant) => Rows::participant($participant, withRepresentatives: true))->values(),
            'invitations' => $manage
                ? $project->invitations()->with(['invitedOrganization', 'invitingOrganization', 'inviter', 'acceptedOrganization'])->latest()->get()
                    ->map(fn (Invitation $invitation) => Rows::invitation($invitation))->values()
                : [],
            'canManage' => $manage,
            'grantable' => $manage ? Capability::grantableBy($this->access()) : [],
            'capabilities' => Capability::options(),
            'relationships' => Relationship::options(),
            'parents' => $participants->filter(fn (Participant $participant) => $participant->isLive())
                ->map(fn (Participant $participant) => ['value' => $participant->id, 'label' => "{$participant->organization->name} ({$participant->relationshipLabel()})"])->values(),
        ]);
    }

    public function invite(Request $request, Project $project): RedirectResponse
    {
        $validated = $this->validateInvitation($request);
        $invitation = Collaboration::invite($project, $this->member($request), $this->access(), null, $validated);

        return back()->with('success', 'Invitation sent to '.($invitation->invitedOrganization->name ?? $invitation->email).'.');
    }

    public function revoke(Request $request, Project $project, Invitation $invitation): RedirectResponse
    {
        Collaboration::revoke($invitation, $this->member($request), $this->access());

        return back()->with('success', 'Invitation withdrawn.');
    }

    public function resend(Request $request, Project $project, Invitation $invitation): RedirectResponse
    {
        Collaboration::resend($invitation, $this->member($request), $this->access());

        return back()->with('success', 'Invitation sent again.');
    }

    public function capabilities(Request $request, Project $project, Participant $participant): RedirectResponse
    {
        $validated = $request->validate(['capabilities' => ['present', 'array'], 'capabilities.*' => ['string', Rule::in(Capability::keys())]]);
        Collaboration::changeCapabilities($participant, $validated['capabilities'], $this->member($request), $this->access());

        return back()->with('success', "What {$participant->organization->name} may do is updated.");
    }

    public function status(Request $request, Project $project, Participant $participant): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in([Participant::ACTIVE, Participant::SUSPENDED, Participant::COMPLETED, Participant::REVOKED])]]);
        Collaboration::setStatus($participant, $validated['status'], $this->member($request), $this->access());

        return back()->with('success', "{$participant->organization->name} is now {$validated['status']}.");
    }

    /**
     * Organizations to invite: those with a published profile, by name, or
     * any active one by its exact workspace address. Never a list of
     * everyone on the platform.
     */
    public function organizations(Request $request): JsonResponse
    {
        $query = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $here = app(CurrentOrganization::class)->require()->id;

        $published = OrganizationProfile::acrossOrganizations()->where('status', OrganizationProfile::PUBLISHED)->pluck('organization_id');
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($query)).'%';
        $matches = Organization::query()
            ->where('status', Organization::ACTIVE)
            ->where('id', '!=', $here)
            ->where(fn ($where) => $where->where('slug', mb_strtolower($query))
                ->orWhere(fn ($named) => $named->whereIn('id', $published)->whereRaw('lower(name) like ?', [$like])))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'slug', 'country_code']);

        return response()->json($matches->map(fn (Organization $organization) => [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'country' => $organization->country_code,
        ])->values());
    }

    /**
     * @return array{organization_id?: string|null, email?: string|null, contact_name?: string|null, relationship: string, parent_participant_id?: string|null, capabilities: array<array-key, mixed>, message?: string|null}
     */
    public static function validateInvitation(Request $request, bool $withParent = true): array
    {
        /** @var array{organization_id?: string|null, email?: string|null, contact_name?: string|null, relationship: string, parent_participant_id?: string|null, capabilities: array<array-key, mixed>, message?: string|null} */
        return $request->validate([
            'organization_id' => ['nullable', 'uuid', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:organization_id'],
            'contact_name' => ['nullable', 'string', 'max:255', 'required_with:email'],
            'relationship' => ['required', Rule::in(Relationship::keys())],
            'parent_participant_id' => $withParent ? ['nullable', 'uuid'] : ['prohibited'],
            'capabilities' => ['present', 'array'],
            'capabilities.*' => ['string', Rule::in(Capability::keys())],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function access(): Access
    {
        return app(CurrentAccess::class)->get() ?? abort(403);
    }
}
