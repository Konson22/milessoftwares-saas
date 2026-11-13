<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Invitation links land on the auth host. Guests sign in (or register) first
 * and come back here. The page reveals the organization's name only to the
 * account the invitation was sent to.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(private readonly Invitations $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitations->findByToken($token);
        $user = $request->user();

        $status = match (true) {
            $invitation === null => 'invalid',
            $invitation->effectiveStatus() !== 'pending' => $invitation->effectiveStatus(),
            mb_strtolower((string) $user->email) !== $invitation->email => 'wrong_account',
            default => 'pending',
        };

        $organization = $status === 'pending' ? Organization::query()->find($invitation?->organization_id) : null;

        return Inertia::render('central/invitations/show', [
            'status' => $status,
            'token' => $token,
            'organization' => $organization === null ? null : ['displayName' => $organization->display_name],
            'invitedEmail' => $status === 'wrong_account' ? $this->maskEmail((string) $invitation?->email) : null,
            'emailVerified' => $user->email_verified_at !== null,
        ]);
    }

    public function accept(Request $request, string $token): SymfonyResponse
    {
        try {
            $result = $this->invitations->accept($request->user(), $token);
        } catch (MembershipActionDenied $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        // A note about the role is shown here, next to the business in the
        // list; otherwise straight into the business on its own host.
        $url = $result['organization']->workspaceUrl();
        if ($result['roleNote'] !== null || $url === null) {
            Inertia::flash('toast', [
                'type' => $result['roleNote'] === null ? 'success' : 'warning',
                'message' => $result['roleNote'] ?? __('Welcome to :name.', ['name' => $result['organization']->display_name]),
            ]);

            return redirect()->route('workspaces');
        }

        return Inertia::location($url);
    }

    /**
     * Enough for the invitee to recognise which address was invited without
     * disclosing it to whoever holds the link.
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('•', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }
}
