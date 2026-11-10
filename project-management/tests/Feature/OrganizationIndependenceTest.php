<?php

use App\Actions\Beneficiaries;
use App\Actions\Organizations;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Partnership;
use App\Models\User;
use App\Models\UserNotification;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Hosts;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| An organization stands on its own: its workspace on the platform needs no
| donor, and it can both fund others and be funded. What it funds or receives
| is a relationship, not what kind of organization it is.
*/

/**
 * The organization, with $owner as its owner; funding others when $funds.
 *
 * @return array{Organization, User}
 */
function independentOrganization(string $name, bool $funds = false, ?User $owner = null): array
{
    $slug = Str::slug($name);
    $owner = test()->member($slug, owner: true, user: $owner);
    $organization = test()->organization($slug);
    if ($funds) {
        Organizations::setFunding($organization, true, $owner);
    }

    return [$organization->refresh(), $owner];
}

it('opens a workspace that only does its own work, without the funding sections', function () {
    [$organization, $owner] = independentOrganization('Solo Works');
    $this->actingAs($owner);

    expect($organization->isDonor())->toBeFalse();
    foreach (['', 'projects', 'admin/settings'] as $page) {
        $this->get($this->organizationUrl('solo-works', $page))->assertOk();
    }

    $this->get($this->organizationUrl('solo-works', 'beneficiaries'))->assertNotFound();
});

it('opens an organization that funds others, with its funding sections', function () {
    [, $owner] = independentOrganization('Both Ways', funds: true);
    $this->actingAs($owner);

    $this->get($this->organizationUrl('both-ways', 'projects'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('organization.funds', true));
    $this->get($this->organizationUrl('both-ways', 'beneficiaries'))->assertOk();
});

it('links to each organization on its own workspace', function () {
    [$both] = independentOrganization('Both Ways', funds: true);

    expect(Hosts::organization($both, '/projects/beneficiaries/1'))->toBe($this->organizationUrl('both-ways', 'beneficiaries/1'));
});

it('opens a notification on the page it is about', function () {
    [$both, $owner] = independentOrganization('Both Ways', funds: true);
    $this->actingAs($owner);

    $notification = app(CurrentOrganization::class)->run($both, fn () => UserNotification::query()->create([
        'user_id' => $owner->id,
        'kind' => UserNotification::MESSAGE,
        'title' => 'A report',
        'url' => '/projects/beneficiaries',
        'created_at' => now(),
    ]));

    $this->post($this->organizationUrl('both-ways', "notifications/{$notification->id}/open"))->assertRedirect('/projects/beneficiaries');
    expect($notification->refresh()->read_at)->not->toBeNull();
});

it('turns funding on and off from settings, audited', function () {
    [$organization, $owner] = independentOrganization('Own Work');
    $this->actingAs($owner);
    $settings = $this->organizationUrl('own-work', 'admin/settings');

    $this->get($settings)->assertInertia(fn (Assert $page) => $page
        ->where('settings.funding', ['enabled' => false, 'canTurnOff' => true])
        ->where('organization.funds', false));
    $this->get($this->organizationUrl('own-work', 'beneficiaries'))->assertNotFound();

    $this->from($settings)->put($this->organizationUrl('own-work', 'admin/settings/funding'), ['funds' => '1'])->assertRedirect($settings);
    expect($organization->refresh()->isDonor())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'organization.funding_enabled')->where('organization_id', $organization->id)->exists())->toBeTrue();
    $this->get($this->organizationUrl('own-work', 'beneficiaries'))->assertOk();

    $this->from($settings)->put($this->organizationUrl('own-work', 'admin/settings/funding'), ['funds' => '0'])->assertRedirect($settings);
    expect($organization->refresh()->isDonor())->toBeFalse()
        ->and($organization->capabilities)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'organization.funding_disabled')->where('organization_id', $organization->id)->exists())->toBeTrue();
    $this->get($this->organizationUrl('own-work', 'beneficiaries'))->assertNotFound();
});

it('keeps funding on while there are partnerships', function () {
    [$organization, $owner] = independentOrganization('Funder', funds: true);
    Beneficiaries::invite($organization, $owner, ['name' => 'Water Now', 'email' => 'admin@water.example.org', 'label' => 'Implementer', 'currency' => 'USD', 'timezone' => 'UTC', 'country_code' => null]);
    $this->actingAs($owner);

    $this->get($this->organizationUrl('funder', 'admin/settings'))->assertInertia(fn (Assert $page) => $page
        ->where('settings.funding', ['enabled' => true, 'canTurnOff' => false]));

    $this->from($this->organizationUrl('funder', 'admin/settings'))->put($this->organizationUrl('funder', 'admin/settings/funding'), ['funds' => '0'])
        ->assertSessionHasErrors('funding');
    expect($organization->refresh()->isDonor())->toBeTrue();
});

it('needs organization.manage to change funding', function () {
    [$organization] = independentOrganization('Own Work');
    $this->actingAs($this->member('own-work', ['projects.view']));

    $this->put($this->organizationUrl('own-work', 'admin/settings/funding'), ['funds' => '1'])->assertForbidden();
    expect($organization->refresh()->isDonor())->toBeFalse();
});

it('connects an organization that funds others, but never to itself or twice', function () {
    [$funder, $owner] = independentOrganization('Funder', funds: true);
    [$middle] = independentOrganization('Middle', funds: true, owner: $owner);
    [$own] = independentOrganization('Own Work', owner: $owner);

    $partnership = new Partnership(['email' => $owner->email, 'name' => 'Anyone']);
    $partnership->forceFill(['donor_organization_id' => $funder->id]);

    expect(Beneficiaries::connectable($partnership, $middle))->toBeTrue()
        ->and(Beneficiaries::connectable($partnership, $own))->toBeTrue()
        ->and(Beneficiaries::connectable($partnership, $funder))->toBeFalse();

    Beneficiaries::acceptDirect(Beneficiaries::inviteOrganization($funder, $own, $owner), $own, $owner);
    expect(Beneficiaries::connectable($partnership, $own))->toBeFalse();
});

it('shows a funder only its own partnerships when the one it funds also funds others', function () {
    [$funder, $first] = independentOrganization('First Funder', funds: true);
    [$middle, $second] = independentOrganization('Middle', funds: true);
    Beneficiaries::invite($middle, $second, ['name' => 'Far Away', 'email' => 'far@example.org', 'label' => 'Implementer', 'currency' => 'USD', 'timezone' => 'UTC', 'country_code' => null]);
    Beneficiaries::invite($funder, $first, ['name' => 'Near By', 'email' => 'near@example.org', 'label' => 'Implementer', 'currency' => 'USD', 'timezone' => 'UTC', 'country_code' => null]);
    $this->actingAs($first);

    $this->get($this->organizationUrl('first-funder', 'beneficiaries'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('partnerships', 1)
        ->where('partnerships.0.name', 'Near By'));

    // Nor can it open the other organization: the platform refuses.
    $this->get($this->organizationUrl('middle', 'beneficiaries'))->assertForbidden();
});
