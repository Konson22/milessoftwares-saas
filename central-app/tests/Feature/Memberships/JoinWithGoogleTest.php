<?php

use App\Models\User;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    Notification::fake();
    config([
        'services.google.client_id' => 'test-client',
        'services.google.client_secret' => 'test-secret',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
    ]);
    $this->prepareAuthorizationFixtures();

    [, $this->token] = app(Invitations::class)->invite($this->owner, 'nyandeng@gmail.com');
    app(TenantContext::class)->clear();
});

function googleAs(string $email, string $id = 'google-nyandeng'): void
{
    $user = (new SocialiteUser)->setRaw(['email_verified' => true])->map([
        'id' => $id,
        'name' => 'Nyandeng Akol',
        'email' => $email,
        'avatar' => 'https://lh3.googleusercontent.com/a/nyandeng',
    ]);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

function joinWithGoogleUrl(string $token): string
{
    return PlatformHosts::url(PlatformHosts::auth(), 'join/'.$token.'/google');
}

function isMemberOf(User $user, string $organizationId): bool
{
    return OrganizationMembership::query()->withoutGlobalScopes()
        ->where('user_id', $user->id)->where('organization_id', $organizationId)->exists();
}

it('offers joining with Google on the invitation page', function () {
    $this->get(PlatformHosts::url(PlatformHosts::auth(), 'join/'.$this->token))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canJoinWithGoogle', true));
});

it('joins with the Google account of the invited address, without a password', function () {
    $this->get(joinWithGoogleUrl($this->token))->assertRedirect(route('google.redirect'));

    googleAs('nyandeng@gmail.com');
    $this->get(route('google.callback'))->assertRedirect((string) $this->organization->workspaceUrl());

    $user = User::query()->where('email', 'nyandeng@gmail.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Nyandeng Akol')
        ->and($user->avatar_url)->toBe('https://lh3.googleusercontent.com/a/nyandeng')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(isMemberOf($user, $this->organization->id))->toBeTrue()
        ->and(Invitation::acrossTenants()->sole()->status)->toBe('accepted');
});

it('joins even while public registration is closed', function () {
    config(['fortify.features' => array_values(array_diff(config('fortify.features'), [Features::registration()]))]);

    $this->get(joinWithGoogleUrl($this->token));
    googleAs('nyandeng@gmail.com');
    $this->get(route('google.callback'))->assertRedirect((string) $this->organization->workspaceUrl());

    expect(isMemberOf(User::query()->where('email', 'nyandeng@gmail.com')->sole(), $this->organization->id))->toBeTrue();
});

it('does not accept the invitation for a Google account with another address', function () {
    $this->get(joinWithGoogleUrl($this->token));
    googleAs('someone.else@gmail.com', 'google-other');

    $this->get(route('google.callback'))
        ->assertRedirect(PlatformHosts::url(PlatformHosts::auth(), 'invitations/'.$this->token));

    expect(Invitation::acrossTenants()->sole()->status)->toBe('pending')
        ->and(isMemberOf(User::query()->where('email', 'someone.else@gmail.com')->sole(), $this->organization->id))->toBeFalse();
});

it('sends a used or unknown invitation back to the invitation page', function () {
    $this->get(joinWithGoogleUrl(str_repeat('x', 48)))
        ->assertRedirect(route('join.show', str_repeat('x', 48)));
});
