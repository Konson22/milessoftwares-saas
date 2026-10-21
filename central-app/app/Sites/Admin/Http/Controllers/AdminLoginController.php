<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Administration\Models\PlatformStaff;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The admin host's own sign-in: email and password, then the two-factor
 * code, on admin.<root> itself. Only active staff with confirmed MFA get
 * past the password; the session is host-only, so signing in to the
 * customer apps never signs anyone in to admin, or the other way round.
 */
class AdminLoginController extends Controller
{
    /** Who passed the password and is due the code, and until when. */
    private const PENDING = 'admin.login.pending';

    private const PENDING_SECONDS = 300;

    public function create(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended('/');
        }

        return Inertia::render('admin/auth/login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();
        // Hash even without an account, so timing does not tell who exists.
        $passwordOk = Hash::check($credentials['password'], $user->password ?? Hash::make(Str::random(32))) && $user !== null;

        if ($user === null || ! $passwordOk || $user->status !== 'active' || ! $this->isActiveStaff($user)) {
            event(new Failed('web', $user, ['email' => $credentials['email']]));

            throw ValidationException::withMessages(['email' => __('These details do not match an administrator account.')]);
        }

        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at === null) {
            throw ValidationException::withMessages(['email' => __('Administrators need two-factor authentication. Turn it on in your account security settings, then sign in here again.')]);
        }

        $request->session()->put(self::PENDING, ['id' => $user->id, 'until' => now()->getTimestamp() + self::PENDING_SECONDS]);

        return redirect()->to(PlatformHosts::url(PlatformHosts::admin(), 'two-factor'));
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return $this->backToLogin($request);
        }

        return Inertia::render('admin/auth/two-factor');
    }

    public function verify(Request $request, TwoFactorAuthenticationProvider $provider): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return $this->backToLogin($request);
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $recovery = (string) ($validated['recovery_code'] ?? '');
        $code = (string) ($validated['code'] ?? '');

        if ($recovery !== '') {
            $match = collect($user->recoveryCodes())->first(fn (string $candidate) => hash_equals($candidate, $recovery));

            if ($match !== null) {
                $user->replaceRecoveryCode($match);
                event(new RecoveryCodeReplaced($user, $match));
            }

            $passed = $match !== null;
        } else {
            $passed = $code !== '' && $provider->verify(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret), $code);
        }

        if (! $passed) {
            event(new TwoFactorAuthenticationFailed($user));

            throw ValidationException::withMessages([$recovery !== '' ? 'recovery_code' : 'code' => __('That code is not valid.')]);
        }

        event(new ValidTwoFactorAuthenticationCodeProvided($user));
        $request->session()->forget(self::PENDING);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(PlatformHosts::url(PlatformHosts::admin(), '/'));
    }

    public function destroy(Request $request): HttpResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location(PlatformHosts::url(PlatformHosts::admin(), 'login'));
    }

    /**
     * The person who passed the password on this session, while they are
     * still staff and within the time allowed for the code.
     */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || ! is_string($pending['id'] ?? null) || (int) ($pending['until'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $user = User::query()->find($pending['id']);

        return $user !== null && $user->status === 'active' && $this->isActiveStaff($user) ? $user : null;
    }

    private function isActiveStaff(User $user): bool
    {
        $staff = PlatformStaff::query()->where('user_id', $user->getKey())->first();

        return $staff !== null && $staff->isActive();
    }

    private function backToLogin(Request $request): RedirectResponse
    {
        $request->session()->forget(self::PENDING);

        return redirect()->to(PlatformHosts::url(PlatformHosts::admin(), 'login'))
            ->with('status', __('Sign in again to continue.'));
    }
}
