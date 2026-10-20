<?php

use App\Models\User;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Notifications\OrganizationInvitation;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    Notification::fake();
    $this->prepareAuthorizationFixtures();

    // Sent while the fixtures' tenant context is still set.
    [, $this->token] = app(Invitations::class)->invite($this->owner, 'nyandeng@example.test');
    app(TenantContext::class)->clear();
});

function joinUrl(string $token): string
{
    return PlatformHosts::url(PlatformHosts::auth(), 'join/'.$token);
}

it('sends invitation emails to the join page', function () {
    expect((new OrganizationInvitation($this->organization->display_name, 'Owner', $this->token, now()->addWeek()))->acceptUrl())
        ->toBe(joinUrl($this->token));
});

it('lets someone new choose a password and join in one step, with their email proven by the link', function () {
    $this->get(joinUrl($this->token))->assertOk();

    $this->post(joinUrl($this->token), [
        'name' => 'Nyandeng Akol',
        'password' => 'a-Much-better-one-42',
        'password_confirmation' => 'a-Much-better-one-42',
    ])->assertRedirect((string) $this->organization->workspaceUrl());

    $user = User::query()->where('email', 'nyandeng@example.test')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->created_by_organization_id)->toBeNull()
        ->and(OrganizationMembership::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)->where('organization_id', $this->organization->id)->exists())->toBeTrue();

    // The link is used up.
    auth()->logout();
    $this->post(joinUrl($this->token), [
        'name' => 'Someone else',
        'password' => 'another-Strong-one-77',
        'password_confirmation' => 'another-Strong-one-77',
    ])->assertSessionHasErrors('invitation');
});

it('sends the browser to the workspace with a full page load after joining from the form', function () {
    // The form posts with Inertia (XHR), which cannot follow a redirect to
    // another host itself.
    $this->withHeader('X-Inertia', 'true')->post(joinUrl($this->token), [
        'name' => 'Nyandeng Akol',
        'password' => 'a-Much-better-one-42',
        'password_confirmation' => 'a-Much-better-one-42',
    ])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', (string) $this->organization->workspaceUrl());
});

it('sends people who already have an account to sign in and accept instead', function () {
    User::factory()->create(['email' => 'Nyandeng@example.test']);
    $accept = PlatformHosts::url(PlatformHosts::app(), 'invitations/'.$this->token);

    $this->get(joinUrl($this->token))->assertRedirect($accept);
    $this->post(joinUrl($this->token), [
        'name' => 'Impostor',
        'password' => 'a-Much-better-one-42',
        'password_confirmation' => 'a-Much-better-one-42',
    ])->assertRedirect($accept);

    expect(User::query()->whereRaw('lower(email) = ?', ['nyandeng@example.test'])->count())->toBe(1);
});

it('creates nothing for an unknown link', function () {
    $this->post(joinUrl(str_repeat('a', 48)), [
        'name' => 'Nobody',
        'password' => 'a-Much-better-one-42',
        'password_confirmation' => 'a-Much-better-one-42',
    ])->assertSessionHasErrors('invitation');

    expect(User::query()->where('name', 'Nobody')->exists())->toBeFalse();
});
