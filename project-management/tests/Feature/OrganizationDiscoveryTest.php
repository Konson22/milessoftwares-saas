<?php

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Models\Partnership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
| Phase 5, discovery: organizations on Tatook Projects find each other by name
| to invite one another. Before, only those that had published a marketplace
| profile could be found by name, and none had in production. Each may turn
| it off; only name, workspace address and country are shown.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00'));
    Notification::fake();
});

function discoverySearch(string $q, string $slug = 'acme'): TestResponse
{
    return test()->getJson(test()->organizationUrl($slug, 'collaboration/organizations?q='.urlencode($q)));
}

it('finds an organization by part of its name without a published profile', function () {
    $this->organization('water-now')->forceFill(['name' => 'Water Now Initiative', 'country_code' => 'SS'])->save();
    $this->actingAsMember('acme', [], owner: true);

    discoverySearch('now init')->assertOk()->assertExactJson([[
        'id' => $this->organization('water-now')->id, 'name' => 'Water Now Initiative', 'slug' => 'water-now', 'country' => 'SS',
    ]]);
    // Case does not matter; its own organization is never offered.
    discoverySearch('WATER')->assertOk()->assertJsonCount(1);
    discoverySearch('acme')->assertOk()->assertJsonCount(0);
});

it('respects an organization that turned discovery off, and leaves out inactive ones', function () {
    $this->organization('quiet-ngo')->forceFill(['name' => 'Quiet NGO', 'discoverable' => false])->save();
    $this->organization('gone-ngo')->forceFill(['name' => 'Gone NGO', 'status' => Organization::SUSPENDED])->save();
    $this->actingAsMember('acme', [], owner: true);

    discoverySearch('ngo')->assertOk()->assertJsonCount(0);
    // Its exact workspace address still finds it: whoever has it was told it.
    discoverySearch('quiet-ngo')->assertOk()->assertJsonPath('0.name', 'Quiet NGO');
    discoverySearch('gone-ngo')->assertOk()->assertJsonCount(0);

    // A published marketplace profile is found by name even with discovery off.
    $quiet = $this->organization('quiet-ngo');
    $this->asTenant('quiet-ngo', fn () => OrganizationProfile::query()->create(['summary' => 'Quiet work'])->forceFill(['status' => OrganizationProfile::PUBLISHED, 'published_at' => now()])->save());
    discoverySearch('quiet')->assertOk()->assertJsonPath('0.id', $quiet->id);
});

it('shows ten at most, and only to those who invite', function () {
    foreach (range(1, 12) as $n) {
        $this->organization("health-{$n}")->forceFill(['name' => "Health Partner {$n}"])->save();
    }

    $this->actingAsMember('acme', ['projects.view']);
    discoverySearch('health')->assertForbidden();

    $this->actingAsMember('acme', ['projects.view', 'projects.partners.manage']);
    discoverySearch('health')->assertOk()->assertJsonCount(10);
    discoverySearch('h')->assertUnprocessable();
});

it('lets a donor find an organization and invite it to partner, once', function () {
    $this->organization('water-now')->forceFill(['name' => 'Water Now'])->save();
    $donor = $this->organization('global-fund');
    $donor->forceFill(['capabilities' => [Organization::DONOR]])->save();
    $this->actingAsMember('global-fund', ['organization.manage', 'grants.view']);

    $found = discoverySearch('water', 'global-fund')->assertOk()->json('0.id');
    $this->post($this->organizationUrl('global-fund', 'beneficiaries/organizations'), ['organization_id' => $found, 'label' => 'implementer'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Invitation sent to Water Now. Its administrators accept or decline it in Tatook.');
    // Again: the one waiting is answered, not a second.
    $this->post($this->organizationUrl('global-fund', 'beneficiaries/organizations'), ['organization_id' => $found, 'label' => 'implementer'])
        ->assertSessionHas('success', 'Water Now has your invitation already: it is waiting for their answer.');
    expect(Partnership::query()->where('invited_organization_id', $found)->count())->toBe(1);

    // Their administrators see it waiting in Connected donors.
    $this->actingAsMember('water-now', [], owner: true);
    $this->get($this->organizationUrl('water-now', 'donor-connections'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('invitations', 1)->where('invitations.0.donor', 'Global Fund'));

    // Without organization.manage at the donor: not allowed.
    $this->actingAsMember('global-fund', ['grants.view']);
    $this->post($this->organizationUrl('global-fund', 'beneficiaries/organizations'), ['organization_id' => $found, 'label' => 'implementer'])->assertForbidden();
});

it('lets an administrator turn discovery off and on, audited', function () {
    $this->actingAsMember('acme', [], owner: true);
    $this->get($this->organizationUrl('acme', 'admin/settings'))->assertOk()->assertInertia(fn ($page) => $page->where('settings.discoverable', true));

    $this->put($this->organizationUrl('acme', 'admin/settings/discoverable'), ['discoverable' => '0'])->assertSessionHasNoErrors();
    expect($this->organization('acme')->fresh()?->discoverable)->toBeFalse()
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'organization.discoverable_off')->exists())->toBeTrue();

    $this->actingAsMember('acme', ['projects.view']);
    $this->put($this->organizationUrl('acme', 'admin/settings/discoverable'), ['discoverable' => '1'])->assertForbidden();
});

it('shows a donor, searching the marketplace by name, organizations without a profile', function () {
    $this->organization('water-now')->forceFill(['name' => 'Water Now', 'country_code' => 'SS'])->save();
    $this->organization('quiet-water')->forceFill(['name' => 'Quiet Water', 'discoverable' => false])->save();
    $donor = $this->organization('global-fund');
    $donor->forceFill(['capabilities' => [Organization::DONOR], 'account_type' => Organization::FUNDER])->save();
    $this->actingAsMember('global-fund', [], owner: true);

    // Without a search: published profiles only, never a list of everyone.
    $this->get($this->organizationUrl('global-fund', 'marketplace'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('organizations', 0)->has('others', 0));

    $this->get($this->organizationUrl('global-fund', 'marketplace?q=water'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('others', 1)
            ->where('others.0', ['id' => $this->organization('water-now')->id, 'slug' => 'water-now', 'name' => 'Water Now', 'countryCode' => 'SS'])
            ->where('canInvite', true));
});
