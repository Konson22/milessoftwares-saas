<?php

use App\Models\Funder;
use App\Models\Organization;
use App\Models\Partnership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| What an organization signs up as on the platform (a donor or funder, or an
| organization that runs the work), and a donor creating the workspace of an
| organization it funds: the platform holds it until the person named claims
| it, and it connects to the donor the first time it opens Projects.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00'));
    Notification::fake();
});

it('turns funding others on for an organization that signed up as a donor', function () {
    $this->platform()->organization('global-fund', ['organization_type' => 'donor']);
    $this->actingAsMember('global-fund', [], owner: true);

    $this->get($this->organizationUrl('global-fund', 'beneficiaries'))->assertOk();

    expect(Organization::query()->where('slug', 'global-fund')->sole()->isDonor())->toBeTrue();
});

it('leaves funding off for an organization that runs the work', function () {
    $this->platform()->organization('water-now', ['organization_type' => 'organization']);
    $this->actingAsMember('water-now', [], owner: true);

    $this->get($this->organizationUrl('water-now', 'beneficiaries'))->assertNotFound();

    expect(Organization::query()->where('slug', 'water-now')->sole()->isDonor())->toBeFalse();
});

/**
 * The donor's owner creates a workspace for "Water Now"; answers the platform's claim id.
 *
 * @param  array<string, mixed>  $details
 */
function createBeneficiaryWorkspace(array $details = []): string
{
    test()->platform()->organization('global-fund', ['organization_type' => 'donor']);
    test()->actingAsMember('global-fund', [], owner: true);

    test()->post(test()->organizationUrl('global-fund', 'beneficiaries/workspaces'), [
        'name' => 'Water Now', 'label' => 'implementer', 'owner_name' => 'Amina Deng', 'email' => 'Amina@Water.example',
        'currency' => 'ssp', 'timezone' => 'Africa/Juba', 'country_code' => 'ss', ...$details,
    ])->assertSessionHasNoErrors()->assertRedirect();

    return (string) array_key_last(test()->platform()->sponsored);
}

it('asks the platform to create the workspace and waits for it to be claimed', function () {
    $claim = createBeneficiaryWorkspace(['slug' => 'water-now']);

    expect($this->platform()->sponsored[$claim])
        ->toMatchArray(['display_name' => 'Water Now', 'owner_email' => 'amina@water.example', 'owner_name' => 'Amina Deng', 'slug' => 'water-now', 'country_code' => 'SS', 'default_currency' => 'SSP']);

    $partnership = Partnership::query()->sole();
    expect($partnership->isCreated())->toBeTrue()
        ->and($partnership->isAccepted())->toBeFalse()
        ->and($partnership->email)->toBe('amina@water.example');

    $this->get($this->organizationUrl('global-fund', 'beneficiaries'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/beneficiaries/index')
        ->where('partnerships.0.status', 'invited')
        ->where('partnerships.0.created', fn ($created) => str_starts_with((string) $created, 'water-now.')));
});

it('shows what the platform refuses next to the form', function () {
    $this->platform()->refuseSponsoring = ['slug' => ['This address is already taken.']];

    $this->platform()->organization('global-fund', ['organization_type' => 'donor']);
    $this->actingAsMember('global-fund', [], owner: true);
    $this->post($this->organizationUrl('global-fund', 'beneficiaries/workspaces'), [
        'name' => 'Water Now', 'label' => 'implementer', 'owner_name' => 'Amina Deng', 'email' => 'amina@water.example',
        'currency' => 'SSP', 'timezone' => 'Africa/Juba', 'country_code' => 'SS', 'slug' => 'taken-name',
    ])->assertSessionHasErrors(['slug' => 'This address is already taken.']);

    expect(Partnership::query()->exists())->toBeFalse();
});

it('connects the claimed workspace to the donor the first time its owner opens Projects', function () {
    $claim = createBeneficiaryWorkspace();
    $slug = $this->platform()->claimSponsored($claim);

    $owner = $this->actingAsMember($slug, [], owner: true, user: User::factory()->create(['email' => 'amina@water.example']));
    $this->get($this->organizationUrl($slug, ''))->assertOk();

    $partnership = Partnership::query()->sole();
    $beneficiary = Organization::query()->where('slug', $slug)->sole();
    expect($partnership->isAccepted())->toBeTrue()
        ->and($partnership->beneficiary_organization_id)->toBe($beneficiary->id)
        ->and($partnership->accepted_by)->toBe($owner->id)
        // An organization that runs the work: no funding sections of its own.
        ->and($beneficiary->isDonor())->toBeFalse()
        // The donor is a funder there, like any other; nothing is shared yet.
        ->and($this->asTenant($slug, fn () => Funder::query()->sole()->name))->toBe('Global Fund');
});

it('does not connect a member who is not its owner', function () {
    $claim = createBeneficiaryWorkspace();
    $slug = $this->platform()->claimSponsored($claim);

    $this->actingAsMember($slug, ['projects.view']);
    $this->get($this->organizationUrl($slug, ''));

    expect(Partnership::query()->sole()->isAccepted())->toBeFalse();
});

it('sends the link again, or withdraws a workspace nobody claimed', function () {
    $claim = createBeneficiaryWorkspace();
    $partnership = Partnership::query()->sole();

    $this->post($this->organizationUrl('global-fund', "beneficiaries/{$partnership->id}/resend"))->assertRedirect();
    expect($this->platform()->sponsored[$claim]['resent'])->toBe(1);

    $this->delete($this->organizationUrl('global-fund', "beneficiaries/{$partnership->id}"))->assertRedirect();
    expect($this->platform()->sponsored[$claim]['status'])->toBe('cancelled')
        ->and(Partnership::query()->exists())->toBeFalse();
});

it('keeps creating workspaces to those who manage the donor', function () {
    $this->platform()->organization('global-fund', ['organization_type' => 'donor']);
    $this->actingAsMember('global-fund', ['grants.view']);

    $this->post($this->organizationUrl('global-fund', 'beneficiaries/workspaces'), [
        'name' => 'Water Now', 'label' => 'implementer', 'owner_name' => 'Amina Deng', 'email' => 'amina@water.example',
        'currency' => 'SSP', 'timezone' => 'Africa/Juba', 'country_code' => 'SS',
    ])->assertForbidden();

    expect($this->platform()->sponsored)->toBe([]);
});

it('answers a repeated request with the workspace made the first time', function () {
    $first = createBeneficiaryWorkspace();
    $second = createBeneficiaryWorkspace();

    expect($second)->toBe($first)
        ->and($this->platform()->sponsored)->toHaveCount(1)
        ->and(Partnership::query()->count())->toBe(1);
});

it('says so when the workspace is gone on the platform, instead of failing', function () {
    $claim = createBeneficiaryWorkspace();
    $partnership = Partnership::query()->sole();
    unset($this->platform()->sponsored[$claim]);

    $this->post($this->organizationUrl('global-fund', "beneficiaries/{$partnership->id}/resend"))->assertStatus(409);
});

it('closes a created workspace that is connected to the donor already', function () {
    $claim = createBeneficiaryWorkspace();
    $slug = $this->platform()->claimSponsored($claim);
    $this->actingAsMember($slug, [], owner: true, user: User::factory()->create(['email' => 'amina@water.example']));
    $this->get($this->organizationUrl($slug, ''))->assertOk();

    // A second workspace made for the same organization, claimed by it later.
    $waiting = Partnership::query()->sole()->replicate();
    $waiting->forceFill(['platform_claim_id' => (string) Str::uuid(), 'beneficiary_organization_id' => null, 'accepted_at' => null, 'accepted_by' => null, 'funder_id' => null])->save();

    $this->get($this->organizationUrl($slug, ''))->assertOk();

    expect(Partnership::query()->count())->toBe(1)
        ->and(Partnership::query()->sole()->isAccepted())->toBeTrue();
});
