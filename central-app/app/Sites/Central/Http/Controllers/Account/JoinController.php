<?php

namespace App\Sites\Central\Http\Controllers\Account;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where invitation emails lead. Someone new chooses their name and password
 * and is in: holding the link proves they read mail at the invited address,
 * so the account starts verified and the invitation is accepted at once.
 * Someone who already has an account signs in and accepts on the app host,
 * as before.
 */
class JoinController extends Controller
{
    use PasswordValidationRules;

    public function __construct(private readonly Invitations $invitations) {}

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = $this->invitations->findByToken($token);
        $status = $invitation?->effectiveStatus() ?? 'invalid';

        if ($status !== 'pending') {
            return Inertia::render('central/auth/join', ['status' => $status]);
        }

        if ($request->user() !== null || $this->accountExists($invitation)) {
            return redirect()->away($this->acceptUrl($token));
        }

        return Inertia::render('central/auth/join', [
            'status' => 'new',
            'token' => $token,
            'email' => $invitation->email,
            'organization' => Organization::query()->find($invitation->organization_id)?->display_name,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->findByToken($token);

        if ($invitation === null || $invitation->effectiveStatus() !== 'pending') {
            throw ValidationException::withMessages(['invitation' => __('This invitation is no longer valid.')]);
        }

        if ($request->user() !== null || $this->accountExists($invitation)) {
            return redirect()->away($this->acceptUrl($token));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        // One transaction: if accepting fails (the inviter lost the right to
        // invite, the organization closed), no stray account is left behind.
        try {
            [$user, $organization] = DB::transaction(function () use ($invitation, $validated, $token): array {
                $user = new User;
                $user->forceFill([
                    'name' => trim($validated['name']),
                    'email' => $invitation->email,
                    'password' => $validated['password'],
                    'email_verified_at' => now(),
                ])->save();

                $result = $this->invitations->accept($user, $token);

                return [$user, $result['organization']];
            });
        } catch (MembershipActionDenied $e) {
            throw ValidationException::withMessages(['invitation' => $e->getMessage()]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return redirect()->away($this->acceptUrl($token));
            }

            throw $e;
        }

        Auth::login($user);
        $request->session()->regenerate();

        // The workspace signs them in through this (now signed-in) host.
        return redirect()->away((string) $organization->workspaceUrl());
    }

    private function accountExists(Invitation $invitation): bool
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])->exists();
    }

    /** The existing flow: sign in on the app host, then accept. */
    private function acceptUrl(string $token): string
    {
        return PlatformHosts::url(PlatformHosts::app(), 'invitations/'.$token);
    }
}
