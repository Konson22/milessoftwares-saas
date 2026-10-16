<?php

namespace App\Sites\Central\Http\Controllers\Account;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First sign-in with a temporary password: the person chooses their own
 * before anything else (RequirePasswordChange), then carries on where they
 * were going.
 */
class PasswordSetupController extends Controller
{
    use PasswordValidationRules;

    public function show(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->mustChangePassword()) {
            return $this->carryOn($request);
        }

        return Inertia::render('central/auth/set-password', [
            'email' => $user->email,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->mustChangePassword()) {
            return $this->carryOn($request);
        }

        $validated = $request->validate(['password' => $this->passwordRules()]);

        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => __('Choose a password of your own, not the temporary one.')]);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'password_change_required_at' => null,
            'temporary_password_expires_at' => null,
        ])->save();

        $audit->record('password.temporary_replaced', $user, [], actor: $user);

        return $this->carryOn($request);
    }

    /**
     * Where the person was going (a workspace or product sign-in), or the
     * dashboard.
     */
    private function carryOn(Request $request): RedirectResponse
    {
        return redirect()->intended(PlatformHosts::url(PlatformHosts::app(), 'dashboard'));
    }
}
