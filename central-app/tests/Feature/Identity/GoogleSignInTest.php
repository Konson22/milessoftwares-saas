<?php

use App\Models\User;
use App\Modules\Identity\Models\AuthenticationEvent;
use App\Modules\Identity\Models\SocialAccount;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    Notification::fake();

    config([
        'services.google.client_id' => 'test-client',
        'services.google.client_secret' => 'test-secret',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
    ]);
});

function fakeGoogleUser(string $id = 'google-123', string $email = 'person@example.com', bool $verified = true): void
{
    $user = (new SocialiteUser)->setRaw(['email_verified' => $verified])->map([
        'id' => $id,
        'name' => 'Pat Person',
        'email' => $email,
    ]);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

it('sends people to Google', function () {
    $this->get(route('google.redirect'))
        ->assertRedirectContains('accounts.google.com');
});

it('is not available until configured', function () {
    config(['services.google.client_id' => null]);

    $this->get(route('google.redirect'))->assertNotFound();
});

it('signs in and links the account with the verified address', function () {
    $user = User::factory()->create(['email' => 'Person@Example.com']);
    fakeGoogleUser();

    $this->get(route('google.callback'))
        ->assertRedirect(PlatformHosts::url(PlatformHosts::app(), 'dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::query()->sole())
        ->user_id->toBe($user->id)
        ->provider_user_id->toBe('google-123');
    expect(AuthenticationEvent::query()->pluck('event')->all())
        ->toContain('social_account_linked', 'login');
});

it('signs in by the linked Google id even after the address changed', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    SocialAccount::query()->forceCreate([
        'user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-123', 'email' => 'old@example.com',
    ]);
    fakeGoogleUser(email: 'new@example.com');

    $this->get(route('google.callback'));

    $this->assertAuthenticatedAs($user);
});

it('creates a verified account for a new address', function () {
    fakeGoogleUser(email: 'new@example.com');

    $this->get(route('google.callback'));

    $user = User::query()->where('email', 'new@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('Pat Person');
});

it('refuses an unverified Google address', function () {
    User::factory()->create(['email' => 'person@example.com']);
    fakeGoogleUser(verified: false);

    $this->get(route('google.callback'))->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(SocialAccount::query()->exists())->toBeFalse();
});

it('does not relink an account already linked to another Google account', function () {
    $user = User::factory()->create(['email' => 'person@example.com']);
    SocialAccount::query()->forceCreate([
        'user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'someone-else',
    ]);
    fakeGoogleUser();

    $this->get(route('google.callback'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('refuses suspended accounts', function () {
    $user = User::factory()->create(['email' => 'person@example.com']);
    $user->forceFill(['status' => 'suspended'])->save();
    fakeGoogleUser();

    $this->get(route('google.callback'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('still asks for the two-factor code', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'person@example.com']);
    fakeGoogleUser();

    $this->get(route('google.callback'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

it('returns to the login page when the person cancels', function () {
    $this->get(route('google.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
});
