<?php

use App\Models\User;
use App\Modules\Identity\Enums\SecurityAlertType;
use App\Modules\Identity\Models\AuthenticationEvent;
use App\Modules\Identity\Models\UserSession;
use App\Modules\Identity\Notifications\SecurityAlert;
use App\Modules\Identity\Support\AuthenticationEventRecorder;
use App\Modules\Identity\Support\SessionTracker;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => Notification::fake());

function signIn(User $user, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Firefox/140.0'): TestResponse
{
    return test()->withHeader('User-Agent', $userAgent)->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);
}

it('records a successful sign-in and starts a tracked session', function () {
    $user = User::factory()->create();

    signIn($user);

    $this->assertAuthenticatedAs($user);

    $event = AuthenticationEvent::query()->where('event', 'login')->sole();
    expect($event->user_id)->toBe($user->id)
        ->and($event->host)->toBe('auth.'.config('platform.root_domain'));

    $session = UserSession::query()->sole();
    expect($session->user_id)->toBe($user->id)
        ->and(session(SessionTracker::SESSION_KEY))->toBe($session->id);
});

it('records failed sign-ins with a hashed identifier only', function () {
    $this->post(route('login.store'), ['email' => 'Nobody@Example.com', 'password' => 'wrong']);

    $event = AuthenticationEvent::query()->sole();

    expect($event->event)->toBe('login_failed')
        ->and($event->user_id)->toBeNull()
        ->and($event->identifier_hash)->toBe(AuthenticationEventRecorder::hashIdentifier('nobody@example.com'))
        ->and(json_encode($event->getAttributes()))->not->toContain('example.com');
});

it('rejects suspended accounts at sign-in with the generic error', function () {
    $user = User::factory()->create();
    $user->forceFill(['status' => 'suspended'])->save();

    signIn($user)->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
    expect(AuthenticationEvent::query()->where('event', 'login_failed')->exists())->toBeTrue();
});

it('does not alert on a first sign-in', function () {
    signIn(User::factory()->create());

    Notification::assertNothingSent();
});

it('alerts on a sign-in from a new device but not from a known one', function () {
    $user = User::factory()->create();

    signIn($user);
    $this->post(route('logout'));

    signIn($user);
    $this->post(route('logout'));
    Notification::assertNothingSent();

    signIn($user, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1')->assertRedirect();

    Notification::assertSentTo($user, SecurityAlert::class, function (SecurityAlert $alert) {
        return $alert->type === SecurityAlertType::NewSignIn && $alert->device === 'Safari on iOS';
    });
});

it('revokes the tracked session on sign-out', function () {
    $user = User::factory()->create();
    signIn($user);

    $this->post(route('logout'));

    $session = UserSession::query()->sole();
    expect($session->revoked_at)->not->toBeNull()
        ->and($session->revoked_reason)->toBe('logout');
    expect(AuthenticationEvent::query()->where('event', 'logout')->exists())->toBeTrue();
});

it('renders the security alert email with a link to the sessions page', function () {
    $user = User::factory()->create();

    $message = (new SecurityAlert(SecurityAlertType::PasswordChanged, now(), '203.0.113.9', 'Firefox on Windows'))
        ->toMail($user);

    expect($message->subject)->toBe('Your password was changed');
    expect((string) $message->render())
        ->toContain('Firefox on Windows')
        ->toContain('203.0.113.9')
        ->toContain('auth.'.config('platform.root_domain'))
        ->toContain('settings/sessions');
});
