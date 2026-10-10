<?php

use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Support\Tenancy\TenantContext;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    $this->prepareAuthorizationFixtures();
    $this->slug = $this->organization->primarySlug->slug;
    $this->member = $this->member();
    $this->grant($this->member, 'retail', 'cashier', Scope::branch($this->juba->id));
    app(TenantContext::class)->clear();
});

function memberStatusUrl(string $membershipId): string
{
    return 'http://'.test()->slug.'.'.config('platform.root_domain')."/manage/members/{$membershipId}/status";
}

it('removes a member and everything they held', function () {
    $this->actingAs($this->owner->user)
        ->put(memberStatusUrl($this->member->id), ['status' => 'removed'])
        ->assertSessionHasNoErrors();

    expect($this->member->refresh()->status)->toBe(MembershipStatus::Removed)
        ->and(RoleAssignment::acrossTenants()->where('membership_id', $this->member->id)->live()->count())->toBe(0);

    // Their next workspace request is refused.
    $this->actingAs($this->member->user)->get('http://'.$this->slug.'.'.config('platform.root_domain').'/')->assertForbidden();
});

it('suspends and reactivates a member', function () {
    $this->actingAs($this->owner->user);

    $this->put(memberStatusUrl($this->member->id), ['status' => 'suspended'])->assertSessionHasNoErrors();
    expect($this->member->refresh()->status)->toBe(MembershipStatus::Suspended);

    $this->put(memberStatusUrl($this->member->id), ['status' => 'active'])->assertSessionHasNoErrors();
    expect($this->member->refresh()->status)->toBe(MembershipStatus::Active);
});

it('protects the owner and the actor\'s own membership', function () {
    $orgAdmin = $this->member();
    app(TenantContext::class)->run($this->organization, fn () => $this->grant($orgAdmin, 'platform', 'access_administrator'));
    app(TenantContext::class)->clear();

    $this->actingAs($orgAdmin->user);
    $this->put(memberStatusUrl($this->owner->id), ['status' => 'removed'])->assertSessionHasErrors('status');
    $this->put(memberStatusUrl($orgAdmin->id), ['status' => 'removed'])->assertSessionHasErrors('status');

    expect($this->owner->refresh()->status)->toBe(MembershipStatus::Active);
});

it('needs organization-wide access management', function () {
    $branchAdmin = $this->member();
    app(TenantContext::class)->run($this->organization, fn () => $this->grant($branchAdmin, 'platform', 'access_administrator', Scope::branch($this->juba->id)));
    app(TenantContext::class)->clear();

    $this->actingAs($branchAdmin->user)
        ->put(memberStatusUrl($this->member->id), ['status' => 'removed'])
        ->assertSessionHasErrors('status');

    expect($this->member->refresh()->status)->toBe(MembershipStatus::Active);
});
