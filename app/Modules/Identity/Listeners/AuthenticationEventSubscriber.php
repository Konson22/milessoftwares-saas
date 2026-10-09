<?php

namespace App\Modules\Identity\Listeners;

use App\Models\User;
use App\Modules\Identity\Enums\SecurityAlertType;
use App\Modules\Identity\Models\AuthenticationEvent;
use App\Modules\Identity\Notifications\SecurityAlert;
use App\Modules\Identity\Support\AuthenticationEventRecorder;
use App\Modules\Identity\Support\DeviceLabel;
use App\Modules\Identity\Support\SessionTracker;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Login audit, session tracking and security alert emails for the web guard.
 */
class AuthenticationEventSubscriber
{
    /**
     * A sign-in from an IP + user agent pair not seen within this many days
     * triggers a new sign-in alert.
     */
    private const KNOWN_DEVICE_DAYS = 90;

    public function __construct(
        private readonly AuthenticationEventRecorder $recorder,
        private readonly SessionTracker $sessions,
    ) {}

    /**
     * Subscribers are built once at boot, so the current request is looked up
     * per event rather than injected.
     */
    private function request(): Request
    {
        return request();
    }

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $isNewDevice = $this->isNewDevice($event->user);

        $this->recorder->record('login', $event->user, context: array_filter([
            'remember' => $event->remember ?: null,
        ]), guard: $event->guard);

        if ($this->request()->hasSession()) {
            $this->sessions->start($event->user, $this->request());
        }

        if ($isNewDevice) {
            $this->alert($event->user, SecurityAlertType::NewSignIn);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $identifier = $event->credentials[Fortify::username()] ?? null;

        $this->recorder->record(
            'login_failed',
            $event->user,
            is_string($identifier) ? $identifier : null,
            guard: $event->guard,
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $identifier = $event->request->input(Fortify::username());

        $this->recorder->record('lockout', identifier: is_string($identifier) ? $identifier : null);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        $this->recorder->record('logout', $event->user, guard: $event->guard);

        $session = $this->request()->hasSession() ? $this->sessions->current($this->request()) : null;

        if ($session !== null) {
            $this->sessions->revoke($session, 'logout', rotateRememberToken: false);
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->recorder->record('password_reset', $event->user);
        $this->sessions->revokeAll($event->user, 'password_reset');
        $this->alert($event->user, SecurityAlertType::PasswordChanged);
    }

    public function handlePasswordUpdated(PasswordUpdatedViaController $event): void
    {
        $user = $event->user;

        $this->recorder->record('password_changed', $user);
        $this->sessions->revokeAll($user, 'password_changed', keep: $this->sessions->current($this->request()));
        $this->alert($user, SecurityAlertType::PasswordChanged);
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->recorder->record('mfa_enabled', $event->user);
        $this->alert($event->user, SecurityAlertType::MfaEnabled);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->recorder->record('mfa_disabled', $event->user);
        $this->alert($event->user, SecurityAlertType::MfaDisabled);
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->recorder->record('mfa_failed', $event->user);
    }

    public function handleTwoFactorPassed(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        $this->recorder->record('mfa_passed', $event->user);
    }

    public function handleRecoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        $this->recorder->record('recovery_code_used', $event->user);
        $this->alert($event->user, SecurityAlertType::RecoveryCodeUsed);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->recorder->record('recovery_codes_generated', $event->user);
    }

    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->recorder->record('passkey_added', $event->user, context: ['passkey_id' => $event->passkey->getKey()]);
        $this->alert($event->user, SecurityAlertType::PasskeyAdded);
    }

    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->recorder->record('passkey_removed', $event->user, context: ['passkey_id' => $event->passkey->getKey()]);
        $this->alert($event->user, SecurityAlertType::PasskeyRemoved);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Lockout::class => 'handleLockout',
            Logout::class => 'handleLogout',
            PasswordReset::class => 'handlePasswordReset',
            PasswordUpdatedViaController::class => 'handlePasswordUpdated',
            TwoFactorAuthenticationConfirmed::class => 'handleTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'handleTwoFactorDisabled',
            TwoFactorAuthenticationFailed::class => 'handleTwoFactorFailed',
            ValidTwoFactorAuthenticationCodeProvided::class => 'handleTwoFactorPassed',
            RecoveryCodeReplaced::class => 'handleRecoveryCodeUsed',
            RecoveryCodesGenerated::class => 'handleRecoveryCodesGenerated',
            PasskeyRegistered::class => 'handlePasskeyRegistered',
            PasskeyDeleted::class => 'handlePasskeyDeleted',
        ];
    }

    /**
     * True when the user has signed in before, but never from this IP and user
     * agent within the lookback window. A first-ever sign-in is not alerted.
     */
    private function isNewDevice(User $user): bool
    {
        $recentLogins = AuthenticationEvent::query()
            ->where('user_id', $user->getKey())
            ->where('event', 'login')
            ->where('occurred_at', '>=', now()->subDays(self::KNOWN_DEVICE_DAYS));

        if (! (clone $recentLogins)->exists()) {
            return false;
        }

        return ! $recentLogins
            ->where('ip_address', $this->request()->ip())
            ->where('user_agent', $this->request()->userAgent())
            ->exists();
    }

    private function alert(Authenticatable $user, SecurityAlertType $type): void
    {
        if (! $user instanceof User) {
            return;
        }

        $user->notify(new SecurityAlert(
            $type,
            now(),
            $this->request()->ip(),
            DeviceLabel::from($this->request()->userAgent()),
        ));
    }
}
