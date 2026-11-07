<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Models\SocialAccount;
use App\Modules\Identity\Support\AuthenticationEventRecorder;
use App\Modules\Identity\Support\SessionTracker;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Memberships\Models\Invitation;
use App\Support\Hosts\PlatformHosts;
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
 * sign-in (Login event). The Google profile picture becomes the account's.
 *
 * Started from an invitation (join/{token}/google), the invitation is accepted
 * right after: the invited person needs no password of their own.
 */
class GoogleSignInController
{
    private const PROVIDER = 'google';

    /** The invitation token a "Join with Google" started from. */
    public const JOIN_SESSION_KEY = 'google.join_invitation';

    public function __construct(
        private readonly AuthenticationEventRecorder $recorder,
        private readonly SessionTracker $sessions,
        private readonly Invitations $invitations,
    ) {}

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

        $joinToken = $request->session()->pull(self::JOIN_SESSION_KEY);
        $invitation = is_string($joinToken) ? $this->pendingInvitationFor($joinToken, $email) : null;

        $user = $this->resolveUser(
            $subject,
            $email,
            $emailVerified,
            (string) ($google->getName() ?: $email),
            $google->getAvatar(),
            invited: $invitation !== null,
        );

        if ($user === null || $user->status !== 'active') {
            $this->recorder->record('login_failed', $user, $email ?: null, ['provider' => self::PROVIDER]);

            // Same wording for unknown and suspended accounts, as with passwords.
            return $this->failed(__('We could not sign you in with that Google account.'));
        }

        if (is_string($joinToken)) {
            // After any second factor: the invitation page, to accept there.
            $request->session()->put('url.intended', PlatformHosts::url(PlatformHosts::auth(), 'invitations/'.$joinToken));
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

        if ($invitation !== null) {
            try {
                $organization = $this->invitations->accept($user, (string) $joinToken)['organization'];
                $request->session()->forget('url.intended');

                return redirect()->away((string) $organization->workspaceUrl());
            } catch (MembershipActionDenied $e) {
                // The invitation page explains what went wrong.
                return redirect()->intended(Fortify::redirects('login'))->withErrors(['invitation' => $e->getMessage()]);
            }
        }

        return redirect()->intended(Fortify::redirects('login'));
    }

    /**
     * The invitation behind "Join with Google", when it is still pending and
     * was sent to the address Google proved.
     */
    private function pendingInvitationFor(string $token, string $email): ?Invitation
    {
        $invitation = $this->invitations->findByToken($token);

        return $invitation !== null
            && $invitation->effectiveStatus() === 'pending'
            && $email !== ''
            && mb_strtolower($invitation->email) === $email
            ? $invitation
            : null;
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
     * is open or the person was invited at this address. An unverified
     * Google address never matches or creates anything.
     * An account still waiting for its email link was registered by someone
     * who never proved this address, so the Google owner takes it over clean.
     */
    public function resolveUser(string $subject, string $email, bool $emailVerified, string $name, ?string $avatar = null, bool $invited = false): ?User
    {
        $avatar = self::usableAvatar($avatar);

        $linked = SocialAccount::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_user_id', $subject)
            ->first();

        if ($linked !== null) {
            $linked->forceFill(['email' => $email ?: $linked->email, 'last_used_at' => now()])->save();
            $this->refreshAvatar($linked->user, $avatar);

            return $linked->user;
        }

        if ($subject === '' || $email === '' || ! $emailVerified) {
            return null;
        }

        return DB::transaction(function () use ($subject, $email, $name, $avatar, $invited): ?User {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($user === null) {
                if (! $invited && ! Features::enabled(Features::registration())) {
                    return null;
                }

                // No usable password: the person can set one later through
                // "Forgot your password?".
                $user = new User;
                $user->forceFill([
                    'name' => Str::limit($name, 255, ''),
                    'email' => $email,
                    'avatar_url' => $avatar,
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
                if (! $user->hasVerifiedEmail()) {
                    $this->removeUnprovenAccess($user, $name);
                }
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
            $this->refreshAvatar($user, $avatar);

            return $user;
        });
    }

    /**
     * Only an https address of reasonable length is shown as a picture.
     */
    private static function usableAvatar(?string $avatar): ?string
    {
        $avatar = trim((string) $avatar);

        return str_starts_with($avatar, 'https://') && strlen($avatar) <= 2048 ? $avatar : null;
    }

    /** Google's picture follows the Google account; none from Google keeps the old one. */
    private function refreshAvatar(?User $user, ?string $avatar): void
    {
        if ($user !== null && $avatar !== null && $user->avatar_url !== $avatar) {
            $user->forceFill(['avatar_url' => $avatar])->save();
        }
    }

    /**
     * Whoever registered with this address before its owner signed in: their
     * password, sessions and app tokens stop working (tokens would otherwise
     * come alive once the address counts as confirmed). An account an
     * organization's administrator added keeps its temporary password: the
     * address was vouched for, and that person is expected to use it.
     */
    private function removeUnprovenAccess(User $user, string $name): void
    {
        $user->forceFill(['name' => Str::limit($name, 255, ''), 'password' => Str::random(64)])->save();

        $this->sessions->revokeAll($user, 'unproven_registration');

        $tokenIds = DB::table('oauth_access_tokens')->where('user_id', $user->getKey())->where('revoked', false)->pluck('id');
        DB::table('oauth_access_tokens')->whereIn('id', $tokenIds)->update(['revoked' => true]);
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);

        $this->recorder->record('unproven_registration_replaced', $user, context: ['provider' => self::PROVIDER]);
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors([Fortify::username() => $message]);
    }
}
