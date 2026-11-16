<?php

use App\Models\User;
use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Miles\SharedModuleKit\EventTokenVerifier;
use Miles\SharedModuleKit\InvalidEventToken;
use Tests\Support\IntegrationFixtures;

/*
| Products reading each other's integration API (ADR-0019 §9): only the
| pairs in EventTokenIssuer::PRODUCT_PAIRS (Projects <-> M&E), for an
| organization entitled to both, optionally on behalf of one of its active
| members. A read token is not an event token, and the reverse.
*/

uses(IntegrationFixtures::class);

beforeEach(function () {
    $this->prepareIntegrationFixtures();
    foreach (['finance', 'projects', 'mel'] as $catalog) {
        app(CatalogImporter::class)->import(json_decode((string) file_get_contents(base_path("tests/Fixtures/catalogs/{$catalog}.json")), true));
    }
    $this->projects = Application::query()->where('code', 'projects')->sole();
    $this->mel = Application::query()->where('code', 'mel')->sole();
    $this->finance = Application::query()->where('code', 'finance')->sole();
    foreach ([$this->projects, $this->mel] as $application) {
        app(ManageEntitlements::class)->grantManual($this->organization, $application, null, 'Integration test');
    }
    $this->projectsClient = app(ManageIntegration::class)->registerServiceClient($this->projects, 'Projects backend', null);
    $this->melClient = app(ManageIntegration::class)->registerServiceClient($this->mel, 'M&E backend', null);
    $this->financeClient = app(ManageIntegration::class)->registerServiceClient($this->finance, 'Finance backend', null);
});

function readTokenFor(array $client, string $audience, ?string $actingUser = null, ?string $organizationId = null, string $scope = 'integration.read'): TestResponse
{
    return test()->withToken(test()->serviceToken($scope, $client))
        ->postJson(test()->apiUrl('integration-tokens'), array_filter([
            'organization_id' => $organizationId ?? test()->organization->id,
            'audience' => $audience,
            'acting_user' => $actingUser,
        ]));
}

function readVerifier(string $audience, string $scope = 'integration.read'): EventTokenVerifier
{
    return new EventTokenVerifier(PlatformHosts::url(PlatformHosts::auth()), (string) file_get_contents(Passport::keyPath('oauth-public.key')), $audience, null, $scope);
}

it('lets Projects read M&E, and M&E read Projects, for an organization entitled to both', function () {
    $toMel = readTokenFor($this->projectsClient, 'mel')->assertOk()->assertJsonPath('expires_in', 300);
    $token = readVerifier('mel')->verify('Bearer '.$toMel->json('token'));
    expect($token->sourceApp)->toBe('projects')
        ->and($token->organizationId)->toBe($this->organization->id)
        ->and($token->scope)->toBe('integration.read')
        ->and($token->actingUser)->toBeNull();

    $toProjects = readTokenFor($this->melClient, 'projects')->assertOk();
    expect(readVerifier('projects')->verify($toProjects->json('token'))->sourceApp)->toBe('mel');
});

it('names the acting user only when they are an active member of the organization', function () {
    $member = $this->member();
    $token = readVerifier('projects')->verify(readTokenFor($this->melClient, 'projects', $member->user_id)->assertOk()->json('token'));
    expect($token->actingUser)->toBe(strtolower($member->user_id));

    // Someone from elsewhere, or a member who left, is refused.
    $stranger = User::factory()->create();
    readTokenFor($this->melClient, 'projects', $stranger->id)->assertForbidden()->assertJsonPath('error', 'not_member');
    app(TenantContext::class)->run($this->organization, fn () => $member->forceFill(['status' => MembershipStatus::Removed, 'removed_at' => now()])->save());
    readTokenFor($this->melClient, 'projects', $member->user_id)->assertForbidden()->assertJsonPath('error', 'not_member');
});

it('refuses unless the organization is entitled to both products, and active', function () {
    $mel = app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->current()->where('application_id', $this->mel->id)->sole());
    app(ManageEntitlements::class)->revoke($this->organization, $mel, null, 'Test');
    readTokenFor($this->projectsClient, 'mel')->assertNotFound()->assertJsonPath('error', 'not_entitled');
    readTokenFor($this->melClient, 'projects')->assertNotFound()->assertJsonPath('error', 'not_entitled');

    app(ManageEntitlements::class)->grantManual($this->organization, $this->mel, null, 'Back');
    readTokenFor($this->projectsClient, 'mel')->assertOk();

    // A subscription that ran out says so, apart from none at all.
    DB::table('entitlements')->where('organization_id', $this->organization->id)->where('application_id', $this->mel->id)->whereNull('revoked_at')
        ->update(['valid_until' => now()->addDay()]);
    $this->travel(2)->days();
    readTokenFor($this->projectsClient, 'mel')->assertStatus(422)->assertJsonPath('error', 'entitlement_expired');
    $this->travelBack();
    readTokenFor($this->projectsClient, 'mel')->assertOk();

    $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
    readTokenFor($this->projectsClient, 'mel')->assertStatus(422)->assertJsonPath('error', 'organization_inactive');
});

it('refuses any pair that is not listed', function () {
    app(ManageEntitlements::class)->grantManual($this->organization, $this->finance, null, 'Integration test');
    // Not Finance reading a product, not a product reading Finance, not reading itself.
    readTokenFor($this->financeClient, 'projects')->assertStatus(422)->assertJsonPath('error', 'unknown_partner');
    readTokenFor($this->projectsClient, 'finance')->assertStatus(422)->assertJsonPath('error', 'unknown_partner');
    readTokenFor($this->projectsClient, 'projects')->assertStatus(422)->assertJsonPath('error', 'unknown_partner');
    readTokenFor($this->serviceClient, 'mel')->assertStatus(422)->assertJsonPath('error', 'unknown_partner');
});

it('keeps read tokens and event tokens apart', function () {
    // The read endpoint needs its own scope; an events.issue token does not open it.
    readTokenFor($this->projectsClient, 'mel', scope: 'events.issue')->assertForbidden()->assertJsonPath('error', 'insufficient_scope');

    $read = readTokenFor($this->projectsClient, 'mel')->json('token');
    expect(fn () => readVerifier('mel', 'events.write')->verify($read))->toThrow(InvalidEventToken::class)
        ->and(fn () => readVerifier('projects')->verify($read))->toThrow(InvalidEventToken::class);
});
