<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Beneficiaries;
use App\Http\Controllers\Controller;
use App\Models\Partnership;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Hosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A donor's invitation to an organization it funds.
 *
 * The emailed link opens on the donor's workspace (landing), where the
 * person invited is usually not a member: they say which workspace their
 * organization uses and go on there (open). In their own workspace, someone
 * who manages the organization accepts or declines it (show, accept,
 * decline). An organization without a workspace starts one on the platform.
 */
class PartnershipController extends Controller
{
    public function landing(string $token): Response
    {
        $partnership = Partnership::findOpen($token);

        return Inertia::render('portal/partnerships/landing', $partnership === null ? ['token' => $token, 'invitation' => null] : [
            'token' => $token,
            'invitation' => $this->summary($partnership),
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

        return Inertia::location(Hosts::organization($slug, route('partnerships.show', ['token' => $token], false)));
    }

    public function show(string $token): Response|RedirectResponse
    {
        $partnership = Partnership::findOpen($token);
        if ($partnership === null) {
            return to_route('home')->with('warning', 'This invitation has been used, withdrawn or has expired. Ask the donor for a new one.');
        }

        $organization = app(CurrentOrganization::class)->require();

        return Inertia::render('portal/partnerships/show', [
            'token' => $token,
            ...$this->summary($partnership),
            'connectable' => Beneficiaries::connectable($partnership, $organization),
            'here' => $organization->name,
        ]);
    }

    public function accept(Request $request, string $token): SymfonyResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $partnership = Beneficiaries::accept($token, app(CurrentOrganization::class)->require(), $user);

        return to_route('home')->with('success', "{$partnership->name} is connected to {$partnership->donor->name}.");
    }

    /**
     * The donor hears, and the link stops working.
     */
    public function decline(Request $request, string $token): SymfonyResponse
    {
        $partnership = Partnership::findOpen($token);
        abort_if($partnership === null, 404);

        $user = $request->user();
        Beneficiaries::decline($partnership, $user instanceof User ? $user : null);

        return to_route('home')->with('success', "You declined the invitation from {$partnership->donor->name}.");
    }

    /**
     * @return array{donor: string, inviter: string, organization: string, label: string, email: string, expiresAt: string}
     */
    private function summary(Partnership $partnership): array
    {
        return [
            'donor' => $partnership->donor->name,
            'inviter' => $partnership->inviter->name,
            'organization' => $partnership->name,
            'label' => $partnership->labelText(),
            'email' => $partnership->email,
            'expiresAt' => $partnership->expires_at->toIso8601String(),
        ];
    }
}
