<?php

use App\Models\Document;
use App\Models\FundingListing;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The funding marketplace. An organization publishes a profile and lists
| projects seeking funding, each a public copy it types itself; donors find
| only what is published, and never a project, budget or grant behind it.
*/

beforeEach(function () {
    Storage::fake('local');
});

const PROFILE = [
    'summary' => 'Clean water for rural schools.',
    'description' => 'We drill and maintain boreholes with communities.',
    'country_code' => 'ss',
    'location' => 'Juba',
    'operating_areas' => 'Central Equatoria, Jonglei',
    'sectors' => ['wash', 'education'],
    'website' => 'https://water.example.org',
];

function funder(string $slug = 'global-fund'): User
{
    $organization = test()->organization($slug);
    $organization->forceFill(['capabilities' => [Organization::DONOR]])->save();

    return test()->actingAsMember($slug, ['grants.view']);
}

function implementerProject(string $slug = 'water-now', string $code = 'WELL', int $budget = 9_000_000): Project
{
    test()->organization($slug);

    return test()->asTenant($slug, function () use ($code, $budget) {
        $project = new Project(['code' => $code, 'name' => 'Borehole programme', 'description' => 'Internal notes: supplier quotes attached.', 'status' => Project::ACTIVE, 'currency' => 'USD', 'budget_minor' => $budget]);
        $project->forceFill(['created_by' => User::factory()->create()->id])->save();

        return $project;
    });
}

/**
 * Signs in an owner of the implementer, publishes its profile and one listing.
 */
function publishedListing(string $slug = 'water-now'): FundingListing
{
    $project = implementerProject($slug);
    test()->actingAsMember($slug, [], owner: true);

    test()->put(test()->organizationUrl($slug, 'public-profile'), PROFILE)->assertSessionHasNoErrors();
    test()->post(test()->organizationUrl($slug, 'public-profile/publish'))->assertSessionHasNoErrors();
    test()->post(test()->organizationUrl($slug, 'funding-listings'), ['project_id' => $project->id])->assertRedirect();
    $listing = test()->asTenant($slug, fn () => FundingListing::query()->sole());
    test()->put(test()->organizationUrl($slug, "funding-listings/{$listing->id}"), [
        'title' => 'Boreholes for 12 schools', 'summary' => 'Twelve boreholes and school hygiene clubs.', 'sector' => 'wash',
        'country_code' => 'SS', 'currency' => 'USD', 'target' => '50000', 'confirmed' => '10000', 'funding_status' => 'partially_funded',
    ])->assertSessionHasNoErrors();
    test()->post(test()->organizationUrl($slug, "funding-listings/{$listing->id}/publish"))->assertSessionHasNoErrors();

    return test()->asTenant($slug, fn () => $listing->fresh() ?? $listing);
}

it('lets an organization work with no profile, donor or listing', function () {
    implementerProject();
    $this->actingAsMember('water-now', [], owner: true);

    $this->get($this->organizationUrl('water-now', 'projects'))->assertOk();
    $this->get($this->organizationUrl('water-now', 'public-profile'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/marketplace/profile')
        ->where('profile.status', 'draft'));
    expect(OrganizationProfile::acrossOrganizations()->count())->toBe(0);
});

it('keeps drafts private, and publishes only what is complete', function () {
    $project = implementerProject();
    $this->actingAsMember('water-now', [], owner: true);

    $this->post($this->organizationUrl('water-now', 'public-profile/publish'))->assertSessionHasErrors('publish');
    $this->put($this->organizationUrl('water-now', 'public-profile'), PROFILE)->assertSessionHasNoErrors();

    // A listing waits for the profile, and needs a description, sector and target.
    $this->post($this->organizationUrl('water-now', 'funding-listings'), ['project_id' => $project->id]);
    $listing = $this->asTenant('water-now', fn () => FundingListing::query()->sole());
    expect($listing->title)->toBe('Borehole programme')
        ->and($listing->target_minor)->toBe(0)
        ->and($listing->publication_status)->toBe('draft');
    $this->post($this->organizationUrl('water-now', "funding-listings/{$listing->id}/publish"))->assertSessionHasErrors('publish');

    funder();
    $this->get($this->organizationUrl('global-fund', 'marketplace'))->assertInertia(fn (Assert $page) => $page->has('organizations', 0));
    $this->get($this->organizationUrl('global-fund', 'marketplace/organizations/water-now'))->assertNotFound();
    $this->get($this->organizationUrl('global-fund', "marketplace/projects/{$listing->id}"))->assertNotFound();
});

it('shows donors published profiles and listings, with only their public fields', function () {
    $listing = publishedListing();
    funder();

    $this->get($this->organizationUrl('global-fund', 'marketplace?sector=wash&country=ss'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/marketplace/organizations')
        ->has('organizations', 1)
        ->where('organizations.0.name', 'Water Now')
        ->where('organizations.0.listings', 1));
    $this->get($this->organizationUrl('global-fund', 'marketplace?sector=health'))->assertInertia(fn (Assert $page) => $page->has('organizations', 0));

    $this->get($this->organizationUrl('global-fund', 'marketplace/projects?q=borehole&status=partially_funded'))->assertInertia(fn (Assert $page) => $page
        ->has('listings', 1)
        ->where('listings.0.targetMinor', 5_000_000)
        ->where('listings.0.confirmedMinor', 1_000_000)
        ->where('listings.0.remainingMinor', 4_000_000));

    $page = $this->get($this->organizationUrl('global-fund', "marketplace/projects/{$listing->id}"))->assertOk();
    $json = json_encode($page->viewData('page')['props']['listing']);
    // The project's own record never leaves the organization.
    expect($json)->not->toContain('Internal notes')
        ->and($json)->not->toContain('9000000')
        ->and($json)->not->toContain($listing->project_id);

    $this->get($this->organizationUrl('global-fund', 'marketplace/organizations/water-now'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('organization.operatingAreas', ['Central Equatoria', 'Jonglei'])
        ->has('organization.listings', 1));
});

it('takes a listing off the marketplace when it or its profile is unpublished', function () {
    $listing = publishedListing();

    $this->post($this->organizationUrl('water-now', "funding-listings/{$listing->id}/unpublish"))->assertSessionHasNoErrors();
    expect(FundingListing::visible()->count())->toBe(0);

    $this->post($this->organizationUrl('water-now', "funding-listings/{$listing->id}/publish"))->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('water-now', 'public-profile/unpublish'))->assertSessionHasNoErrors();
    expect(FundingListing::visible()->count())->toBe(0)
        ->and(OrganizationProfile::visible()->count())->toBe(0)
        // Nothing deleted.
        ->and(FundingListing::acrossOrganizations()->count())->toBe(1);

    // A suspended organization drops out too.
    $this->post($this->organizationUrl('water-now', 'public-profile/publish'));
    $this->organization('water-now')->forceFill(['status' => Organization::SUSPENDED])->save();
    expect(OrganizationProfile::visible()->count())->toBe(0);
});

it('lists a project once, and only one of the organization’s own', function () {
    $project = implementerProject();
    $other = implementerProject('other-ngo', 'OTH');
    $this->actingAsMember('water-now', [], owner: true);

    $this->post($this->organizationUrl('water-now', 'funding-listings'), ['project_id' => $project->id]);
    $this->post($this->organizationUrl('water-now', 'funding-listings'), ['project_id' => $project->id]);
    expect(FundingListing::acrossOrganizations()->count())->toBe(1);

    $this->post($this->organizationUrl('water-now', 'funding-listings'), ['project_id' => $other->id])->assertNotFound();
    $foreign = $this->asTenant('other-ngo', function () use ($other) {
        $listing = new FundingListing(['title' => 'Theirs', 'currency' => 'USD']);
        $listing->forceFill(['project_id' => $other->id, 'created_by' => User::factory()->create()->id, 'publication_status' => 'draft'])->save();

        return $listing;
    });
    $this->get($this->organizationUrl('water-now', "funding-listings/{$foreign->id}"))->assertNotFound();
    $this->post($this->organizationUrl('water-now', "funding-listings/{$foreign->id}/publish"))->assertNotFound();
});

it('refuses confirmed funding above the target', function () {
    $listing = publishedListing();

    $this->put($this->organizationUrl('water-now', "funding-listings/{$listing->id}"), [
        'title' => 'Boreholes', 'currency' => 'USD', 'target' => '100', 'confirmed' => '150', 'funding_status' => 'seeking',
    ])->assertSessionHasErrors('confirmed');
});

it('needs the publish permission to change the profile, and funding switched on to browse', function () {
    implementerProject();
    $this->actingAsMember('water-now', ['projects.view', 'grants.view']);
    $this->get($this->organizationUrl('water-now', 'public-profile'))->assertForbidden();
    $this->put($this->organizationUrl('water-now', 'public-profile'), PROFILE)->assertForbidden();

    // An organization that does not fund others has no marketplace.
    $this->get($this->organizationUrl('water-now', 'marketplace'))->assertNotFound();

    // At a donor, browsing needs grants.view.
    $this->organization('global-fund')->forceFill(['capabilities' => [Organization::DONOR]])->save();
    $this->actingAsMember('global-fund', ['organization.audit.view']);
    $this->get($this->organizationUrl('global-fund', 'marketplace'))->assertForbidden();
});

it('serves only the documents and logo a published profile or listing shows', function () {
    $this->actingAsMember('water-now', [], owner: true);
    implementerProject();
    $this->post($this->organizationUrl('water-now', 'documents'), ['title' => 'Annual report', 'category' => 'report', 'file' => UploadedFile::fake()->create('annual.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('water-now', 'documents'), ['title' => 'Salaries', 'category' => 'policy', 'confidential' => true, 'file' => UploadedFile::fake()->create('pay.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    [$public, $secret] = $this->asTenant('water-now', fn () => [
        Document::query()->where('title', 'Annual report')->sole(),
        Document::query()->where('title', 'Salaries')->sole(),
    ]);

    // A confidential document cannot be chosen.
    $this->put($this->organizationUrl('water-now', 'public-profile'), [...PROFILE, 'documents' => [$secret->id]])->assertSessionHasErrors('documents');
    $this->put($this->organizationUrl('water-now', 'public-profile'), [...PROFILE, 'documents' => [$public->id]])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('water-now', 'public-profile/logo'), ['logo' => UploadedFile::fake()->image('logo.png')])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('water-now', 'public-profile/logo'), ['logo' => UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('logo');

    funder();
    // Still a draft: nothing to download.
    $this->get($this->organizationUrl('global-fund', "marketplace/documents/{$public->id}"))->assertNotFound();
    $this->get($this->organizationUrl('global-fund', 'marketplace/organizations/water-now/logo'))->assertNotFound();

    $this->asTenant('water-now', fn () => OrganizationProfile::query()->sole()->forceFill(['status' => OrganizationProfile::PUBLISHED])->save());
    $this->get($this->organizationUrl('global-fund', "marketplace/documents/{$public->id}"))->assertOk()->assertDownload('annual.pdf');
    $this->get($this->organizationUrl('global-fund', "marketplace/documents/{$secret->id}"))->assertNotFound();
    $this->get($this->organizationUrl('global-fund', 'marketplace/organizations/water-now/logo'))->assertOk();

    // Made confidential later: no longer served.
    $this->asTenant('water-now', fn () => $public->forceFill(['confidential' => true])->save());
    $this->get($this->organizationUrl('global-fund', "marketplace/documents/{$public->id}"))->assertNotFound();
});

it('is on the portal for an organization that funds others', function () {
    publishedListing();
    funder();

    $this->get($this->organizationUrl('global-fund', 'marketplace'))->assertOk();
});
