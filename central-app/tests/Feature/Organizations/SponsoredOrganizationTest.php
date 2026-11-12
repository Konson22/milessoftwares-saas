<?php

use App\Models\User;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Enums\OrganizationType;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationClaim;
use App\Modules\Organizations\Notifications\WorkspaceCreatedForYou;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\IntegrationFixtures;

/*
| An application creates a workspace for another organization on behalf of one
| of its own (Projects: a donor for its grantee). It waits, pending and without
| an owner, until the person named claims it; then it is theirs.
*/

uses(IntegrationFixtures::class);

beforeEach(function () {
    $this->prepareIntegrationFixtures();
    config(['platform.sponsoring_applications' => ['retail']]);
    Notification::fake();

    $this->sponsorUser = User::query()->findOrFail($this->owner->user_id);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sponsoredInput(array $overrides = []): array
{
    return [
        'sponsor_organization_id' => test()->organization->id,
        'sponsor_user_id' => test()->sponsorUser->id,
        'display_name' => 'Water Now',
        'slug' => 'water-now',
        'owner_name' => 'Amina Deng',
        'owner_email' => 'Amina@Water.example',
        'country_code' => 'SS',
        'default_currency' => 'SSP',
        'timezone' => 'Africa/Juba',
        ...$overrides,
    ];
}

/**
 * Creates the workspace through the API; answers the token from the email.
 */
function sponsorWorkspace(array $overrides = []): string
{
    test()->withToken(test()->serviceToken('organizations.sponsor'))
        ->postJson(test()->apiUrl('sponsored-organizations'), sponsoredInput($overrides))
        ->assertCreated()
        ->assertJsonPath('organization.slug', $overrides['slug'] ?? 'water-now')
        ->assertJsonPath('status', 'pending');

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, WorkspaceCreatedForYou::class, function (WorkspaceCreatedForYou $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return (string) $token;
}

it('creates a pending workspace with no owner, and emails the person named', function () {
    sponsorWorkspace();
    app(TenantContext::class)->clear();

    $organization = Organization::query()->where('display_name', 'Water Now')->sole();
    expect($organization->status)->toBe(OrganizationStatus::Pending)
        ->and($organization->owner_membership_id)->toBeNull()
        ->and($organization->organization_type)->toBe(OrganizationType::Organization)
        ->and(OrganizationMembership::acrossTenants()->where('organization_id', $organization->id)->exists())->toBeFalse()
        ->and(OrganizationClaim::query()->sole()->email)->toBe('amina@water.example');
});

it('makes the person who claims it its owner, with the trial of the application that asked', function () {
    $token = sponsorWorkspace();
    $amina = User::factory()->create(['email' => 'amina@water.example']);

    $this->actingAs($amina)
        ->post(PlatformHosts::url(PlatformHosts::auth(), "workspaces/claim/{$token}"))
        ->assertRedirect();
    app(TenantContext::class)->clear();

    $organization = Organization::query()->where('display_name', 'Water Now')->sole();
    $owner = OrganizationMembership::acrossTenants()->where('organization_id', $organization->id)->sole();
    expect($organization->status)->toBe(OrganizationStatus::Active)
        ->and($organization->owner_membership_id)->toBe($owner->id)
        ->and($owner->user_id)->toBe($amina->id)
        ->and(OrganizationClaim::query()->sole()->status)->toBe(OrganizationClaim::CLAIMED)
        ->and(Entitlement::acrossTenants()->where('organization_id', $organization->id)->where('application_id', $this->retail->id)->exists())->toBeTrue()
        // The sponsor has no place in it.
        ->and(OrganizationMembership::acrossTenants()->where('organization_id', $organization->id)->where('user_id', $this->sponsorUser->id)->exists())->toBeFalse();
});

it('refuses a claim from another account, and a second claim', function () {
    $token = sponsorWorkspace();

    $this->actingAs(User::factory()->create(['email' => 'someone@else.example']))
        ->post(PlatformHosts::url(PlatformHosts::auth(), "workspaces/claim/{$token}"))
        ->assertSessionHasErrors('claim');

    $this->actingAs(User::factory()->create(['email' => 'amina@water.example']))
        ->post(PlatformHosts::url(PlatformHosts::auth(), "workspaces/claim/{$token}"))
        ->assertRedirect();

    $this->actingAs(User::factory()->create(['email' => 'other@water.example']))
        ->post(PlatformHosts::url(PlatformHosts::auth(), "workspaces/claim/{$token}"))
        ->assertSessionHasErrors(['claim' => 'This workspace has already been claimed.']);

    // Opened again, the link says so.
    $this->get(PlatformHosts::url(PlatformHosts::auth(), "workspaces/claim/{$token}"))
        ->assertInertia(fn ($page) => $page->where('status', 'claimed')->where('workspace', null));
});

it('withdraws a workspace nobody claimed and frees its address', function () {
    sponsorWorkspace();
    $claim = OrganizationClaim::query()->sole();

    $this->withToken($this->serviceToken('organizations.sponsor'))
        ->postJson($this->apiUrl("sponsored-organizations/{$claim->id}/cancel"), ['sponsor_organization_id' => $this->organization->id, 'sponsor_user_id' => $this->sponsorUser->id])
        ->assertNoContent();
    app(TenantContext::class)->clear();

    expect(Organization::query()->where('display_name', 'Water Now')->exists())->toBeFalse()
        ->and(OrganizationClaim::query()->exists())->toBeFalse();

    Notification::fake();
    sponsorWorkspace();
});

it('refuses a taken address with the reason', function () {
    sponsorWorkspace();

    $this->withToken($this->serviceToken('organizations.sponsor'))
        ->postJson($this->apiUrl('sponsored-organizations'), sponsoredInput(['owner_email' => 'b@water.example']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');
});

it('is only for applications allowed to sponsor, for an entitled organization, by its member', function () {
    $this->withToken($this->serviceToken('access.read'))
        ->postJson($this->apiUrl('sponsored-organizations'), sponsoredInput())
        ->assertForbidden();

    config(['platform.sponsoring_applications' => []]);
    $this->withToken($this->serviceToken('organizations.sponsor'))
        ->postJson($this->apiUrl('sponsored-organizations'), sponsoredInput())
        ->assertForbidden();

    config(['platform.sponsoring_applications' => ['retail']]);
    $other = Organization::factory()->create();
    $this->withToken($this->serviceToken('organizations.sponsor'))
        ->postJson($this->apiUrl('sponsored-organizations'), sponsoredInput(['sponsor_organization_id' => $other->id]))
        ->assertNotFound();

    $this->withToken($this->serviceToken('organizations.sponsor'))
        ->postJson($this->apiUrl('sponsored-organizations'), sponsoredInput(['sponsor_user_id' => User::factory()->create()->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sponsor');

    // A lapsed entitlement (trial over, subscription cancelled) sponsors nothing.
    Entitlement::acrossTenants()->where('organization_id', $this->organization->id)->update(['revoked_at' => now()]);
    $this->withToken($this->serviceToken('organizations.sponsor'))
        ->postJson($this->apiUrl('sponsored-organizations'), sponsoredInput())
        ->assertNotFound();
});

it('limits workspace creation per sponsor, not per calling server', function () {
    // The throttle middleware's key for a named limiter.
    $key = fn (string $sponsor) => md5('sponsored-create'.'sponsored-create:'.$sponsor);
    $other = Organization::factory()->create();
    for ($i = 0; $i < 30; $i++) {
        RateLimiter::hit($key($this->organization->id), 3600);
    }

    $this->withToken($this->serviceToken('organizations.sponsor'))
        ->postJson($this->apiUrl('sponsored-organizations'), sponsoredInput())
        ->assertTooManyRequests();

    // Another sponsor of the same application is not held back.
    expect(RateLimiter::tooManyAttempts($key($other->id), 30))->toBeFalse();
});

it('tells products what the organization signed up as', function () {
    $this->organization->forceFill(['organization_type' => OrganizationType::Donor])->save();

    $this->withToken($this->serviceToken())
        ->getJson($this->apiUrl("organizations/{$this->organization->id}"))
        ->assertOk()
        ->assertJsonPath('organization_type', 'donor');
});
