<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Administration\Actions\StaffChangeDenied;
use App\Modules\Administration\Actions\StaffInvitations;
use App\Modules\Administration\Models\StaffInvitation;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;

/**
 * Where a staff invitation leads, on the admin host. Two steps: the account
 * (someone new chooses a name and password; someone with an account enters
 * theirs), then two-factor authentication, scanned and confirmed with a
 * code. Only then do they become staff, signed in to admin. Holding the
 * link proves they read mail at the invited address, so a new account
 * starts verified.
 */
class StaffInvitationController extends Controller
{
    use PasswordValidationRules;

    /** The invitation and account that passed the first step on this session. */
    private const PENDING = 'admin.invitation.pending';

    public function __construct(private readonly StaffInvitations $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitations->findPending($token);

        if ($invitation === null) {
            return Inertia::render('admin/auth/invitation', ['step' => 'invalid']);
        }

        $user = $this->pendingUser($request, $invitation);

        if ($user !== null) {
            return Inertia::render('admin/auth/invitation', [
                'step' => 'two-factor',
                'token' => $token,
                'email' => $invitation->email,
                'qrCodeSvg' => $user->twoFactorQrCodeSvg(),
                'setupKey' => Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret),
                'recoveryCodes' => $user->recoveryCodes(),
            ]);
        }

        return Inertia::render('admin/auth/invitation', [
            'step' => 'account',
            'token' => $token,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'hasAccount' => $this->userFor($invitation) !== null,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, string $token, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        $invitation = $this->valid($token);
        $user = $this->userFor($invitation);

        if ($user !== null) {
            $validated = $request->validate(['password' => ['required', 'string']]);

            if (! Hash::check($validated['password'], $user->password) || $user->status !== 'active') {
                throw ValidationException::withMessages(['password' => __('That is not the password for this account.')]);
            }
        } else {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => $this->passwordRules(),
            ]);

            $user = new User;
            $user->forceFill([
                'name' => trim($validated['name']),
                'email' => $invitation->email,
                'password' => $validated['password'],
                'email_verified_at' => now(),
            ])->save();
        }

        // Already set up (an existing account with two-factor on): done.
        if ($user->two_factor_confirmed_at !== null) {
            return $this->finish($request, $invitation, $user);
        }

        $enable($user);
        $request->session()->put(self::PENDING, ['invitation' => $invitation->id, 'user' => $user->id]);

        return redirect()->to(PlatformHosts::url(PlatformHosts::admin(), 'invitation/'.$token));
    }

    public function confirm(Request $request, string $token, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        $invitation = $this->valid($token);
        $user = $this->pendingUser($request, $invitation);

        if ($user === null) {
            return redirect()->to(PlatformHosts::url(PlatformHosts::admin(), 'invitation/'.$token));
        }

        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);

        try {
            $confirm($user, $validated['code']);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['code' => __('That code is not valid. Check the time on your phone and try the newest code.')]);
        }

        return $this->finish($request, $invitation, $user->refresh());
    }

    private function finish(Request $request, StaffInvitation $invitation, User $user): RedirectResponse
    {
        try {
            $this->invitations->accept($invitation, $user);
        } catch (StaffChangeDenied $e) {
            throw ValidationException::withMessages(['invitation' => $e->getMessage()]);
        }

        $request->session()->forget(self::PENDING);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Welcome to the Miles admin.')]);

        return redirect()->to(PlatformHosts::url(PlatformHosts::admin(), '/'));
    }

    private function valid(string $token): StaffInvitation
    {
        $invitation = $this->invitations->findPending($token);

        if ($invitation === null) {
            throw ValidationException::withMessages(['invitation' => __('This invitation is no longer valid. Ask for a new one.')]);
        }

        return $invitation;
    }

    private function userFor(StaffInvitation $invitation): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [$invitation->email])->first();
    }

    /** The account that passed the first step for this invitation, on this session. */
    private function pendingUser(Request $request, StaffInvitation $invitation): ?User
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || ($pending['invitation'] ?? null) !== $invitation->id || ! is_string($pending['user'] ?? null)) {
            return null;
        }

        $user = User::query()->find($pending['user']);

        return $user !== null && $user->two_factor_secret !== null ? $user : null;
    }
}
