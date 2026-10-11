<?php

use App\Models\User;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\CurrentOrganization;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

function switchTo(string $organizationId): TestResponse
{
    return test()->post(PlatformHosts::url(PlatformHosts::app(), "organizations/{$organizationId}/switch"));
}

function membershipIn(Organization $organization, User $user, MembershipStatus $status = MembershipStatus::Active): OrganizationMembership
{
    return OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => $status,
    ]);
}

it('switches to an organization the user belongs to', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    membershipIn($first, $user);
    membershipIn($second, $user);

    $this->actingAs($user);
    switchTo($second->id)->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('organizations', 2)
        ->where('organizations', fn ($organizations) => collect($organizations)->firstWhere('isCurrent', true)['id'] === $second->id));
});

it('refuses to switch into an organization the user does not belong to', function () {
    $user = User::factory()->create();
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    membershipIn($mine, $user);

    $this->actingAs($user);
    switchTo($mine->id);

    switchTo($theirs->id)->assertNotFound();
    expect(session(CurrentOrganization::SESSION_KEY))->toBe($mine->id);

    // Unknown ids look exactly the same.
    switchTo('01a1aaaa-0000-7000-8000-000000000000')->assertNotFound();
});

it('refuses to switch into an organization where the membership is not active', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    membershipIn($organization, $user, MembershipStatus::Suspended);

    $this->actingAs($user);

    switchTo($organization->id)->assertNotFound();
});

it('drops the current organization as soon as the membership is suspended', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $membership = membershipIn($organization, $user);

    $this->actingAs($user);
    switchTo($organization->id);

    app(TenantContext::class)->run($organization, fn () => $membership->forceFill(['status' => MembershipStatus::Suspended])->save());

    $this->get(PlatformHosts::url(PlatformHosts::app(), 'organization/settings'))->assertNotFound();
    expect(session(CurrentOrganization::SESSION_KEY))->toBeNull();
});

it('drops the current organization once it is archived', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    membershipIn($organization, $user);

    $this->actingAs($user);
    switchTo($organization->id);

    $organization->forceFill(['status' => OrganizationStatus::Archived])->save();

    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->has('organizations', 0));
    expect(session(CurrentOrganization::SESSION_KEY))->toBeNull();
});

it('binds the tenant context to the current organization on the app host', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    membershipIn($organization, $user);

    $this->actingAs($user);
    switchTo($organization->id);

    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('tenant.displayName', $organization->display_name));
});

it('runs without a tenant context when no organization is current', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tenant', null));
});
