<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Models\SocialAccount;
use App\Modules\Identity\Support\AuthenticationEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sign in with Google on the auth host. Google proves the email address, so a
 * verified Google address signs in to the account with that email (linking it
 * on first use) or, when registration is open, creates one. The account's own
 * rules still apply afterwards: suspended accounts are refused, two-factor
 * authentication is challenged, and the session is tracked like a password
 * sign-in (Login event).
 */
class GoogleSignInController
{
    private const PROVIDER = 'google';

    public function __construct(private readonly AuthenticationEventRecorder $recorder) {}

    public function redirect(): Response
    {
        abort_unless(self::configured(), 404);

        return self::provider()
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    private static function provider(): AbstractProvider
    {
        $provider = Socialite::driver(self::PROVIDER);

        if (! $provider instanceof AbstractProvider) {
            throw new LogicException('The Google Socialite driver is not an OAuth 2 provider.');
        }

        return $provider;
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::configured(), 404);

        // Cancelled on Google's screen.
        if ($request->query('error') !== null) {
            return $this->failed(__('Google sign-in was cancelled.'));
        }

        try {
            $google = Socialite::driver(self::PROVIDER)->user();

            if (! $google instanceof GoogleUser) {
                throw new LogicException('Unexpected Socialite user type.');
            }
        } catch (Throwable $e) {
            Log::warning('Google sign-in failed', ['exception' => $e->getMessage()]);

            return $this->failed(__('Google sign-in failed. Please try again.'));
        }

        $subject = (string) $google->getId();
        $email = Str::lower(trim((string) $google->getEmail()));
        $emailVerified = filter_var($google->user['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user = $this->resolveUser($subject, $email, $emailVerified, (string) ($google->getName() ?: $email));

        if ($user === null || $user->status !== 'active') {
            $this->recorder->record('login_failed', $user, $email ?: null, ['provider' => self::PROVIDER]);

            // Same wording for unknown and suspended accounts, as with passwords.
            return $this->failed(__('We could not sign you in with that Google account.'));
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        Auth::guard(config('fortify.guard'))->login($user);
        $request->session()->regenerate();

        return redirect()->intended(Fortify::redirects('login'));
    }

    public static function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    /**
     * The linked account first; otherwise the account with this verified
     * address, which is then linked; otherwise a new account if registration
     * is open. An unverified Google address never matches or creates anything.
     */
    private function resolveUser(string $subject, string $email, bool $emailVerified, string $name): ?User
    {
        $linked = SocialAccount::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_user_id', $subject)
            ->first();

        if ($linked !== null) {
            $linked->forceFill(['email' => $email ?: $linked->email, 'last_used_at' => now()])->save();

            return $linked->user;
        }

        if ($subject === '' || $email === '' || ! $emailVerified) {
            return null;
        }

        return DB::transaction(function () use ($subject, $email, $name): ?User {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($user === null) {
                if (! Features::enabled(Features::registration())) {
                    return null;
                }

                // No usable password: the person can set one later through
                // "Forgot your password?".
                $user = new User;
                $user->forceFill([
                    'name' => Str::limit($name, 255, ''),
                    'email' => $email,
                    'password' => Str::random(64),
                    'email_verified_at' => now(),
                ])->save();

                $this->recorder->record('registered', $user, context: ['provider' => self::PROVIDER]);
            } elseif (SocialAccount::query()->where('user_id', $user->getKey())->where('provider', self::PROVIDER)->exists()) {
                // Another Google account is already linked to this user;
                // never relink silently.
                return null;
            } elseif ($user->email_verified_at === null) {
                // Google has proven the address.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $account = new SocialAccount;
            $account->forceFill([
                'user_id' => $user->getKey(),
                'provider' => self::PROVIDER,
                'provider_user_id' => $subject,
                'email' => $email,
                'last_used_at' => now(),
            ])->save();

            $this->recorder->record('social_account_linked', $user, context: ['provider' => self::PROVIDER]);

            return $user;
        });
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors([Fortify::username() => $message]);
    }
}
