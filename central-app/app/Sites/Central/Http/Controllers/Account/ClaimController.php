<?php

namespace App\Sites\Central\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Organizations\Actions\SponsoredOrganizations;
use App\Modules\Organizations\Models\OrganizationClaim;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The link to a workspace a donor created for someone, on the auth host.
 * Guests sign in, or create their account, and come back. The page names the
 * workspace only to the account it was made for.
 */
class ClaimController extends Controller
{
    public function __construct(private readonly SponsoredOrganizations $sponsored) {}

    public function show(Request $request, string $token): Response
    {
        $claim = $this->sponsored->findByToken($token);
        $user = $request->user();

        $status = match (true) {
            $claim === null => 'invalid',
            $claim->effectiveStatus() !== OrganizationClaim::PENDING => $claim->effectiveStatus(),
            mb_strtolower((string) $user->email) !== $claim->email => 'wrong_account',
            default => 'pending',
        };

        return Inertia::render('central/organizations/claim', [
            'status' => $status,
            'token' => $token,
            'workspace' => $status !== 'pending' || $claim === null ? null : [
                'name' => $claim->organization->display_name,
                'host' => PlatformHosts::workspace((string) $claim->organization->primarySlug?->slug),
                'sponsor' => $claim->sponsor->display_name,
                'application' => $claim->application->name,
            ],
            'emailVerified' => $user->email_verified_at !== null,
        ]);
    }

    public function store(Request $request, string $token): SymfonyResponse
    {
        try {
            $claim = $this->sponsored->claim($request->user(), $token);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        // Straight into the application the donor works with.
        $prefix = $claim->application->route_prefix;
        $path = is_string($prefix) && $prefix !== '' ? '/'.$prefix : '/';

        return Inertia::location((string) $claim->organization->workspaceUrl($path));
    }
}
