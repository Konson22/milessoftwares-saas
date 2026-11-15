<?php

namespace App\Http\Controllers\Collaboration;

use App\Access\CurrentAccess;
use App\Collaboration\Capability;
use App\Collaboration\Collaboration;
use App\Collaboration\Models\Invitation;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Hosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * An emailed invitation to take part in a project. The link opens on the
 * inviting organization's workspace (landing), where the person is usually
 * not a member: they say which workspace their organization uses, or start
 * one on the platform first, and go on there (open). In their own
 * workspace, someone who manages its collaboration accepts or declines
 * (show, accept, decline). The token is the only way in: an emailed
 * invitation is never opened by its id.
 */
class InvitationLinkController extends Controller
{
    public function landing(string $token): Response
    {
        $invitation = Invitation::findOpen($token);

        return Inertia::render('portal/collaboration/landing', [
            'token' => $token,
            'invitation' => $invitation === null ? null : $this->summary($invitation),
            'rootDomain' => Hosts::rootDomain(),
            'signUpUrl' => rtrim((string) config('platform-client.issuer'), '/').'/workspaces/create',
        ]);
    }

    /**
     * On to the invitation in the workspace named. Only a well-formed
     * workspace name: the address is ours, never one typed in.
     */
    public function open(Request $request, string $token): SymfonyResponse
    {
        $slug = mb_strtolower(trim((string) $request->validate([
            'workspace' => ['required', 'string', 'max:63', 'regex:/^[a-zA-Z](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/'],
        ], ['workspace.regex' => 'Type the name before .'.Hosts::rootDomain().', for example acme.'])['workspace']));

        return Inertia::location(Hosts::organization($slug, route('collaboration.show', ['token' => $token], false)));
    }

    public function show(string $token): Response|RedirectResponse
    {
        $invitation = Invitation::findOpen($token);
        if ($invitation === null) {
            return to_route('home')->with('warning', 'This invitation has been used, withdrawn or has expired. Ask whoever invited you for a new one.');
        }

        $organization = app(CurrentOrganization::class)->require();

        return Inertia::render('portal/collaboration/show', [
            'token' => $token,
            'invitation' => $this->summary($invitation),
            'here' => $organization->name,
            'acceptable' => ! Collaboration::takesPart($invitation->project, $organization->id) && $organization->id !== $invitation->inviting_organization_id,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::findOpen($token);
        abort_if($invitation === null, 404);

        $participant = Collaboration::accept($invitation, app(CurrentOrganization::class)->require(), $this->member($request), app(CurrentAccess::class)->get() ?? abort(403));

        return to_route('shared-projects.show', ['participant' => $participant->id])->with('success', "You now take part in {$participant->project->name}.");
    }

    public function decline(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::findOpen($token);
        abort_if($invitation === null, 404);

        Collaboration::decline($invitation, app(CurrentOrganization::class)->require(), $this->member($request), app(CurrentAccess::class)->get() ?? abort(403));

        return to_route('home')->with('success', 'Invitation declined.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Invitation $invitation): array
    {
        return [
            'from' => $invitation->invitingOrganization->name,
            'inviter' => $invitation->inviter->name,
            'project' => $invitation->project->name,
            'relationship' => $invitation->relationshipLabel(),
            'capabilities' => Capability::labels(Capability::normalize($invitation->capabilities)),
            'message' => $invitation->message,
            'contact' => $invitation->contact_name,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
        ];
    }
}
