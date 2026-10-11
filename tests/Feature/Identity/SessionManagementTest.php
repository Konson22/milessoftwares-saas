<?php

use App\Models\User;
use App\Modules\Identity\Enums\SecurityAlertType;
use App\Modules\Identity\Models\UserSession;
use App\Modules\Identity\Notifications\SecurityAlert;
use App\Modules\Identity\Support\SessionTracker;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => Notification::fake());

function otherSession(User $user): UserSession
{
    $session = new UserSession;
    $session->forceFill([
        'user_id' => $user->id,
        'host' => 'auth.'.config('platform.root_domain'),
        'ip_address' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Chrome/130.0',
    ])->save();

    return $session->refresh();
}

it('adopts sessions that started without a Login event', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    expect(UserSession::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('lists the user\'s active sessions and marks the current one', function () {
    $user = User::factory()->create();
    otherSession($user);
    otherSession(User::factory()->create());

    $this->actingAs($user)->get(route('sessions.index'));
    $this->get(route('sessions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('central/settings/sessions')
            ->has('sessions', 2)
            ->where('sessions.0.is_current', true)
            ->where('sessions.1.device', 'Chrome on macOS'));
});

it('signs out a single other session', function () {
    $user = User::factory()->create();
    $other = otherSession($user);
    $rememberToken = $user->remember_token;

    $this->actingAs($user)
        ->delete(route('sessions.destroy', $other->id))
        ->assertRedirect();

    expect($other->refresh()->revoked_reason)->toBe('user_revoked')
        ->and($user->refresh()->remember_token)->not->toBe($rememberToken);
});

it('does not let a user revoke someone else\'s session', function () {
    $user = User::factory()->create();
    $victim = otherSession(User::factory()->create());

    $this->actingAs($user)
        ->delete(route('sessions.destroy', $victim->id))
        ->assertNotFound();

    expect($victim->refresh()->revoked_at)->toBeNull();
});

it('requires the password to sign out all other sessions', function () {
    $user = User::factory()->create();
    $other = otherSession($user);

    $this->actingAs($user)
        ->delete(route('sessions.destroy-others'), ['password' => 'wrong'])
        ->assertSessionHasErrors('password');

    expect($other->refresh()->revoked_at)->toBeNull();

    $this->delete(route('sessions.destroy-others'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($other->refresh()->revoked_at)->not->toBeNull();

    $current = UserSession::query()->find(session(SessionTracker::SESSION_KEY));
    expect($current?->revoked_at)->toBeNull();
});

it('signs out a revoked session on its next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    UserSession::query()->update(['revoked_at' => now(), 'revoked_reason' => 'user_revoked']);

    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('signs out suspended accounts on their next request and revokes all sessions', function () {
    $user = User::factory()->create();
    $other = otherSession($user);

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    $user->forceFill(['status' => 'suspended'])->save();

    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->assertGuest();
    expect($other->refresh()->revoked_reason)->toBe('account_suspended');
});

it('signs out other sessions and alerts when the password changes', function () {
    $user = User::factory()->create();
    $other = otherSession($user);

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-Password-123!',
            'password_confirmation' => 'new-Password-123!',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($other->refresh()->revoked_reason)->toBe('password_changed');
    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityAlertType::PasswordChanged);
});
