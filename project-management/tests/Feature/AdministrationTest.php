<?php

use App\Models\AuditEvent;
use App\Models\OrganizationMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\PlatformClient\Api\PlatformApi;

/*
| Administration in Projects, for holders of organization.manage (and
| organization.audit.view for the log): members' units, settings and the
| audit log. Members, roles and the organization's details are the
| platform's: a change there shows on the member's next request.
*/

beforeEach(function () {
    $this->admin = $this->actingAsMember('acme', ['organization.manage', 'organization.audit.view']);
});

function membershipOf(User $user): OrganizationMembership
{
    return OrganizationMembership::query()->where('user_id', $user->id)->firstOrFail();
}

it('lists who has opened Projects, with the roles the platform gives them', function () {
    $this->member('acme', [], owner: true, user: User::factory()->create(['name' => 'Olivia Owner']));

    $this->get($this->organizationUrl('acme', 'admin/members'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/admin/members')
        ->has('members', 2)
        ->where('members.0.name', 'Olivia Owner')
        ->where('members.0.isOwner', true)
        ->where('members.1.roles', ['role'])
        ->where('platformMembersUrl', 'http://acme.milesoftwares.test/members'));
});

it('follows role changes on the platform on the member\'s next request', function () {
    $user = $this->member('acme', ['projects.view']);
    $this->actingAs($user)->get($this->organizationUrl('acme', 'projects'))->assertOk();
    $this->post($this->organizationUrl('acme', 'projects'), ['code' => 'NOPE', 'name' => 'Nope'])->assertForbidden();

    $this->member('acme', ['projects.view', 'projects.manage'], user: $user);
    $this->post($this->organizationUrl('acme', 'projects'), ['code' => 'YES', 'name' => 'Yes'])->assertSessionHasNoErrors();
    expect(membershipOf($user)->permissions)->toBe(['projects.manage', 'projects.view']);

    $this->member('acme', ['documents.view'], user: $user);
    $this->get($this->organizationUrl('acme', 'projects'))->assertForbidden();
});

it('lets in nobody the platform refuses', function () {
    $user = $this->member('acme', ['projects.view']);
    $this->platform()->grant('acme', $user->id, [['permissions' => ['projects.view']]], allowed: false);
    app(PlatformApi::class)->forget($this->organization('acme')->id);

    $this->actingAs($user)->get($this->organizationUrl('acme', 'projects'))->assertForbidden();
});

it('places members in units, but not members of another organization', function () {
    $outsider = $this->member('globex', ['projects.view']);

    $this->put($this->organizationUrl('acme', 'admin/members/'.membershipOf($outsider)->id.'/unit'), ['org_unit_id' => null])->assertNotFound();
});

it('shows the organization as the platform has it', function () {
    $this->platform()->organization('acme', ['display_name' => 'Acme Relief']);
    app(PlatformApi::class)->forget($this->organization('acme')->id);

    $this->get($this->organizationUrl('acme', 'admin/settings'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/admin/settings')
        ->where('settings.name', 'Acme Relief')
        ->where('settings.currency', 'USD'));
});

it('records settings changes in the audit log', function () {
    $this->put($this->organizationUrl('acme', 'admin/settings/funding'), ['funds' => '1'])->assertSessionHasNoErrors();

    $this->get($this->organizationUrl('acme', 'admin/audit'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/admin/audit')
        ->where('events.data.0.action', 'organization.funding_enabled'));
});

it('keeps administration from members without the permission', function () {
    $this->actingAsMember('acme', ['projects.view', 'projects.manage', 'projects.finance.view']);

    foreach (['admin/members', 'admin/settings', 'admin/audit', 'admin/org-units'] as $page) {
        $this->get($this->organizationUrl('acme', $page))->assertForbidden();
    }
    $this->put($this->organizationUrl('acme', 'admin/settings/funding'), ['funds' => '1'])->assertForbidden();
});

it('shows one organization\'s audit log only', function () {
    $this->actingAsMember('globex', ['organization.manage']);
    $this->put($this->organizationUrl('globex', 'admin/settings/funding'), ['funds' => '1']);
    expect(AuditEvent::query()->where('action', 'organization.funding_enabled')->exists())->toBeTrue();

    $this->actingAs($this->admin)->get($this->organizationUrl('acme', 'admin/audit'))
        ->assertInertia(fn (Assert $page) => $page->where('events.data', fn ($events) => collect($events)->where('action', 'organization.funding_enabled')->isEmpty()));
});
