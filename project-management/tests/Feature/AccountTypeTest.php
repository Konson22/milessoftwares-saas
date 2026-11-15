<?php

use App\Actions\Organizations;
use App\Models\AuditEvent;
use App\Models\Organization;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\PlatformClient\Api\Organization as PlatformOrganization;

/*
| Before anything else, an organization says what it uses Projects as: a
| funder (donor) or an implementer. Its menu follows.
*/

function unchosen(string $slug): Organization
{
    $organization = test()->organization($slug);
    $organization->forceFill(['account_type' => null])->save();

    return $organization;
}

it('sends an organization that has not chosen to the account type page first', function () {
    $this->actingAsMember('acme', owner: true);
    unchosen('acme');

    $this->get($this->organizationUrl('acme'))->assertRedirect($this->organizationUrl('acme', 'account-type'));
    $this->get($this->organizationUrl('acme', 'projects'))->assertRedirect($this->organizationUrl('acme', 'account-type'));
    $this->get($this->organizationUrl('acme', 'beneficiaries'))->assertRedirect($this->organizationUrl('acme', 'account-type'));

    $this->get($this->organizationUrl('acme', 'account-type'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/account-type')
        ->where('canChoose', true)
        ->where('suggested', null));
});

it('makes a funder: funding on, the funding dashboard as home, audited', function () {
    $this->actingAsMember('acme', owner: true);
    $organization = unchosen('acme');

    $this->post($this->organizationUrl('acme', 'account-type'), ['account_type' => 'funder'])->assertRedirect();

    $organization->refresh();
    expect($organization->isFunder())->toBeTrue()
        ->and($organization->isDonor())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'organization.account_type_set')->exists())->toBeTrue();
    $this->get($this->organizationUrl('acme'))->assertRedirect($this->organizationUrl('acme', 'funding'));
    $this->get($this->organizationUrl('acme', 'beneficiaries'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('organization.accountType', 'funder'));
    $this->get($this->organizationUrl('acme', 'account-type'))->assertRedirect($this->organizationUrl('acme', ''));
});

it('makes an implementer, without the funding sections', function () {
    $this->actingAsMember('acme', owner: true);
    $organization = unchosen('acme');

    $this->post($this->organizationUrl('acme', 'account-type'), ['account_type' => 'implementer'])->assertRedirect();

    expect($organization->refresh()->account_type)->toBe(Organization::IMPLEMENTER)
        ->and($organization->isDonor())->toBeFalse();
    $this->get($this->organizationUrl('acme', 'projects'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('organization.accountType', 'implementer'));
    $this->get($this->organizationUrl('acme', 'beneficiaries'))->assertNotFound();
});

it('lets only someone who manages the organization choose', function () {
    $this->actingAsMember('acme', ['projects.view']);
    unchosen('acme');

    $this->get($this->organizationUrl('acme', 'account-type'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canChoose', false));
    $this->post($this->organizationUrl('acme', 'account-type'), ['account_type' => 'funder'])->assertForbidden();
    $this->post($this->organizationUrl('acme', 'account-type'), ['account_type' => 'banker'])->assertForbidden();
});

it('refuses anything but funder or implementer', function () {
    $this->actingAsMember('acme', owner: true);
    unchosen('acme');

    $this->post($this->organizationUrl('acme', 'account-type'), ['account_type' => 'banker'])->assertSessionHasErrors('account_type');
});

it('suggests funder to an organization that already funded others', function () {
    $this->actingAsMember('acme', owner: true);
    unchosen('acme')->forceFill(['capabilities' => [Organization::DONOR]])->save();

    $this->get($this->organizationUrl('acme', 'account-type'))->assertInertia(fn (Assert $page) => $page
        ->where('suggested', 'funder'));
});

it('takes the type chosen at sign-up on the platform', function () {
    $donor = Organizations::mirror(PlatformOrganization::fromArray($this->platform()->organization('gives', ['organization_type' => 'donor'])));
    $implementer = Organizations::mirror(PlatformOrganization::fromArray($this->platform()->organization('does', ['organization_type' => 'organization'])));
    $unknown = Organizations::mirror(PlatformOrganization::fromArray($this->platform()->organization('later')));

    expect($donor->account_type)->toBe(Organization::FUNDER)
        ->and($donor->isDonor())->toBeTrue()
        ->and($implementer->account_type)->toBe(Organization::IMPLEMENTER)
        ->and($unknown->needsAccountType())->toBeTrue();
});

it('switches between funder and implementer from settings', function () {
    $owner = $this->actingAsMember('acme', owner: true);
    $organization = $this->organization('acme');
    $settings = $this->organizationUrl('acme', 'admin/settings');

    $this->get($settings)->assertInertia(fn (Assert $page) => $page->where('settings.accountType', 'implementer'));

    $this->from($settings)->put($this->organizationUrl('acme', 'admin/settings/account-type'), ['account_type' => 'funder'])->assertRedirect($settings);
    expect($organization->refresh()->isFunder())->toBeTrue()->and($organization->isDonor())->toBeTrue();

    $this->from($settings)->put($this->organizationUrl('acme', 'admin/settings/account-type'), ['account_type' => 'implementer'])->assertRedirect($settings);
    expect($organization->refresh()->account_type)->toBe(Organization::IMPLEMENTER)->and($organization->isDonor())->toBeFalse();
});
