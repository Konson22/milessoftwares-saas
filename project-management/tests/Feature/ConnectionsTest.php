<?php

use App\Actions\Beneficiaries;
use App\Models\FundingInterest;
use App\Models\FundingListing;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Models\Partnership;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Connections. A donor expresses interest in a listing (never a grant or
| confirmed funding) and the organization answers; a donor invites an
| organization on the platform directly, and the invited side can decline.
| Both ways in use the one partnership record.
*/

beforeEach(function () {
    Notification::fake();
});

function connectionsDonor(string $slug = 'global-fund'): User
{
    $organization = test()->organization($slug);
    $organization->forceFill(['capabilities' => [Organization::DONOR]])->save();

    return test()->actingAsMember($slug, [], owner: true);
}

/**
 * A published profile and listing for an organization, made directly.
 */
function connectionsListing(string $slug = 'water-now'): FundingListing
{
    test()->organization($slug);

    return test()->asTenant($slug, function () {
        $project = new Project(['code' => 'WELL', 'name' => 'Boreholes', 'status' => Project::ACTIVE, 'currency' => 'USD', 'budget_minor' => 9_000_000]);
        $project->forceFill(['created_by' => User::factory()->create()->id])->save();

        $profile = new OrganizationProfile(['summary' => 'Clean water.', 'description' => 'Boreholes.', 'country_code' => 'SS', 'sectors' => ['wash']]);
        $profile->forceFill(['status' => OrganizationProfile::PUBLISHED, 'published_at' => now()])->save();

        $listing = new FundingListing(['title' => 'Boreholes for 12 schools', 'summary' => 'Twelve boreholes.', 'sector' => 'wash', 'currency' => 'USD', 'target_minor' => 5_000_000, 'confirmed_minor' => 1_000_000, 'funding_status' => FundingListing::PARTIALLY_FUNDED]);
        $listing->forceFill(['project_id' => $project->id, 'publication_status' => OrganizationProfile::PUBLISHED, 'published_at' => now(), 'created_by' => $project->created_by])->save();

        return $listing;
    });
}

function expressInterest(FundingListing $listing, string $donor = 'global-fund'): FundingInterest
{
    test()->post(test()->organizationUrl($donor, "marketplace/projects/{$listing->id}/interest"), [
        'contact_name' => 'Grace Akol', 'contact_email' => 'grace@fund.example', 'message' => 'We fund WASH in schools. Can we talk?',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $donorId = Organization::query()->where('slug', $donor)->value('id');

    return FundingInterest::query()->where('donor_organization_id', $donorId)->latest('submitted_at')->firstOrFail();
}

it('records interest without creating a grant or confirmed funding', function () {
    $listing = connectionsListing();
    $publisher = test()->member('water-now', ['marketplace.publish']);
    connectionsDonor();

    $interest = expressInterest($listing);

    expect($interest->status)->toBe(FundingInterest::SUBMITTED)
        ->and($interest->organization_id)->toBe($listing->organization_id)
        ->and(Grant::acrossOrganizations()->count())->toBe(0)
        ->and(FundingListing::acrossOrganizations()->find($listing->id)?->confirmed_minor)->toBe(1_000_000)
        ->and($this->asTenant('water-now', fn () => UserNotification::query()->where('user_id', $publisher->id)->where('title', 'like', '%interested in Boreholes%')->exists()))->toBeTrue();

    $this->get($this->organizationUrl('global-fund', "marketplace/projects/{$listing->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('interest.status', 'submitted')
        ->where('canExpress', false));
});

it('allows one open interest per donor and listing', function () {
    $listing = connectionsListing();
    connectionsDonor();
    expressInterest($listing);

    $this->post($this->organizationUrl('global-fund', "marketplace/projects/{$listing->id}/interest"), [
        'contact_name' => 'Grace', 'contact_email' => 'grace@fund.example', 'message' => 'Again',
    ])->assertSessionHasErrors('message');
    expect(FundingInterest::query()->count())->toBe(1);
});

it('refuses interest from a donor without grants.manage, or in a listing that is not published', function () {
    $listing = connectionsListing();
    $this->organization('global-fund')->forceFill(['capabilities' => [Organization::DONOR]])->save();
    $this->actingAsMember('global-fund', ['grants.view']);

    $this->post($this->organizationUrl('global-fund', "marketplace/projects/{$listing->id}/interest"), [
        'contact_name' => 'Grace', 'contact_email' => 'grace@fund.example', 'message' => 'Hello',
    ])->assertForbidden();

    connectionsDonor();
    $this->asTenant('water-now', fn () => $listing->forceFill(['publication_status' => OrganizationProfile::UNPUBLISHED])->save());
    $this->post($this->organizationUrl('global-fund', "marketplace/projects/{$listing->id}/interest"), [
        'contact_name' => 'Grace', 'contact_email' => 'grace@fund.example', 'message' => 'Hello',
    ])->assertNotFound();
});

it('moves an interest through review to an answer, and tells the donor', function () {
    $listing = connectionsListing();
    $donor = connectionsDonor();
    $interest = expressInterest($listing);

    $this->actingAsMember('water-now', ['marketplace.publish']);
    $this->get($this->organizationUrl('water-now', 'funding-interests'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/marketplace/received-interests')
        ->has('interests', 1)
        ->where('interests.0.donor', 'Global Fund'));

    $this->post($this->organizationUrl('water-now', "funding-interests/{$interest->id}/respond"), ['status' => 'under_review'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('water-now', "funding-interests/{$interest->id}/respond"), ['status' => 'accepted', 'response' => 'Happy to talk.'])->assertSessionHasNoErrors();
    // Settled: no going back.
    $this->post($this->organizationUrl('water-now', "funding-interests/{$interest->id}/respond"), ['status' => 'declined'])->assertSessionHasErrors('status');

    $interest->refresh();
    expect($interest->status)->toBe(FundingInterest::ACCEPTED)
        ->and($interest->response)->toBe('Happy to talk.')
        ->and(Grant::acrossOrganizations()->count())->toBe(0)
        ->and(Partnership::query()->count())->toBe(0)
        ->and($this->asTenant('global-fund', fn () => UserNotification::query()->where('user_id', $donor->id)->where('title', 'like', '%accepted%')->exists()))->toBeTrue();

    // Another organization cannot answer it.
    $this->organization('other-org');
    $this->actingAsMember('other-org', ['marketplace.publish']);
    $this->post($this->organizationUrl('other-org', "funding-interests/{$interest->id}/respond"), ['status' => 'declined'])->assertNotFound();
});

it('invites to partner from an accepted interest, and the organization accepts in the app', function () {
    $listing = connectionsListing();
    connectionsDonor();
    $interest = expressInterest($listing);

    // Not before it is accepted.
    $this->post($this->organizationUrl('global-fund', "marketplace/interests/{$interest->id}/invite"))->assertSessionHasErrors('interest');

    $interest->forceFill(['status' => FundingInterest::ACCEPTED])->save();
    $this->post($this->organizationUrl('global-fund', "marketplace/interests/{$interest->id}/invite"))->assertSessionHasNoErrors();
    $partnership = Partnership::query()->sole();
    expect($partnership->invited_organization_id)->toBe($listing->organization_id)
        ->and($partnership->email)->toBeNull()
        ->and($partnership->isAccepted())->toBeFalse()
        ->and($interest->fresh()?->partnership_id)->toBe($partnership->id);

    // A member without organization.manage cannot answer.
    $this->actingAsMember('water-now', ['marketplace.publish']);
    $this->post($this->organizationUrl('water-now', "donor-connections/{$partnership->id}/accept"))->assertForbidden();

    $admin = $this->actingAsMember('water-now', [], owner: true);
    $this->get($this->organizationUrl('water-now', 'donor-connections'))->assertInertia(fn (Assert $page) => $page
        ->has('invitations', 1)->has('connected', 0));
    $this->post($this->organizationUrl('water-now', "donor-connections/{$partnership->id}/accept"))->assertSessionHasNoErrors();

    $partnership->refresh();
    $waterNow = $this->organization('water-now');
    expect($partnership->beneficiary_organization_id)->toBe($waterNow->id)
        ->and($partnership->accepted_by)->toBe($admin->id)
        ->and($partnership->funder_id)->not->toBeNull()
        // Its owners and records stay its own; still no grant.
        ->and($waterNow->memberships()->where('is_owner', true)->pluck('user_id')->all())->toBe([$admin->id])
        ->and(Grant::acrossOrganizations()->count())->toBe(0);
    $this->get($this->organizationUrl('water-now', 'donor-connections'))->assertInertia(fn (Assert $page) => $page
        ->has('invitations', 0)->where('connected.0.donor', 'Global Fund'));
});

it('invites from a profile directly, and reuses a waiting or accepted partnership', function () {
    $listing = connectionsListing();
    connectionsDonor();

    $this->post($this->organizationUrl('global-fund', 'marketplace/organizations/water-now/invite'))->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('global-fund', 'marketplace/organizations/water-now/invite'))->assertSessionHasNoErrors();
    expect(Partnership::query()->count())->toBe(1);

    $this->get($this->organizationUrl('global-fund', 'marketplace/organizations/water-now'))->assertInertia(fn (Assert $page) => $page
        ->where('connection', 'invited'));

    // Accepted from an interest later: still the same partnership.
    $this->actingAsMember('water-now', [], owner: true);
    $this->post($this->organizationUrl('water-now', 'donor-connections/'.Partnership::query()->value('id').'/accept'))->assertSessionHasNoErrors();
    connectionsDonor();
    $interest = expressInterest($listing);
    $interest->forceFill(['status' => FundingInterest::ACCEPTED])->save();
    $this->post($this->organizationUrl('global-fund', "marketplace/interests/{$interest->id}/invite"))->assertSessionHasNoErrors();
    expect(Partnership::query()->count())->toBe(1)
        ->and($interest->fresh()?->partnership_id)->toBe(Partnership::query()->value('id'));
});

it('lets the invited organization decline, and the donor invite again', function () {
    connectionsListing();
    $donor = connectionsDonor();
    $this->post($this->organizationUrl('global-fund', 'marketplace/organizations/water-now/invite'))->assertSessionHasNoErrors();
    $partnership = Partnership::query()->sole();

    $this->actingAsMember('water-now', [], owner: true);
    $this->post($this->organizationUrl('water-now', "donor-connections/{$partnership->id}/decline"))->assertSessionHasNoErrors();
    // Declined is final for that invitation.
    $this->post($this->organizationUrl('water-now', "donor-connections/{$partnership->id}/accept"))->assertNotFound();

    expect($partnership->fresh()?->isDeclined())->toBeTrue()
        ->and($this->asTenant('global-fund', fn () => UserNotification::query()->where('user_id', $donor->id)->where('title', 'like', '%declined%')->exists()))->toBeTrue();

    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', 'beneficiaries'))->assertInertia(fn (Assert $page) => $page
        ->where('partnerships.0.status', 'declined'));
    $this->post($this->organizationUrl('global-fund', 'marketplace/organizations/water-now/invite'))->assertSessionHasNoErrors();
    expect(Partnership::query()->count())->toBe(2);
});

it('lets the emailed link decline, after which it cannot be accepted', function () {
    $this->organization('global-fund')->forceFill(['capabilities' => [Organization::DONOR]])->save();
    $donor = $this->actingAsMember('global-fund', [], owner: true);
    $partnership = Beneficiaries::invite($this->organization('global-fund'), $donor, [
        'label' => 'implementer', 'name' => 'Water Now', 'email' => 'admin@water.example', 'currency' => 'USD', 'timezone' => 'UTC',
    ]);
    $token = 'tok'.str_repeat('a', 45);
    $partnership->forceFill(['token_hash' => Partnership::hashToken($token)])->save();

    // The link opens on the donor's workspace, which sends them on to theirs.
    auth()->logout();
    $this->get($this->organizationUrl('global-fund', "invitations/{$token}"))->assertOk();
    $this->post($this->organizationUrl('global-fund', "invitations/{$token}"), ['workspace' => 'water-now'])
        ->assertRedirect($this->organizationUrl('water-now', "partnerships/{$token}"));

    $this->actingAsMember('water-now', [], owner: true);
    $this->post($this->organizationUrl('water-now', "partnerships/{$token}/decline"))->assertRedirect();
    expect($partnership->fresh()?->isDeclined())->toBeTrue()
        ->and(Partnership::findOpen($token))->toBeNull();

    $this->post($this->organizationUrl('water-now', "partnerships/{$token}"))->assertNotFound();
});

it('keeps each donor to its own interests and invitations', function () {
    $listing = connectionsListing();
    connectionsDonor('global-fund');
    expressInterest($listing, 'global-fund');
    connectionsDonor('other-fund');
    expressInterest($listing, 'other-fund');

    $this->get($this->organizationUrl('other-fund', 'marketplace/interests'))->assertInertia(fn (Assert $page) => $page
        ->has('interests', 1));
    $theirs = FundingInterest::query()->whereHas('donor', fn ($query) => $query->where('slug', 'global-fund'))->sole();
    $theirs->forceFill(['status' => FundingInterest::ACCEPTED])->save();
    $this->post($this->organizationUrl('other-fund', "marketplace/interests/{$theirs->id}/invite"))->assertNotFound();
    expect(Partnership::query()->count())->toBe(0);
});
