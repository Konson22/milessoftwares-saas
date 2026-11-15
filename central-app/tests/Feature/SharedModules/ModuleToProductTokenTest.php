<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Miles\SharedModuleKit\EventTokenVerifier;
use Miles\SharedModuleKit\InvalidEventToken;
use Tests\Support\IntegrationFixtures;

/*
| The one shared-module-to-product route (ADR-0017 §6): Finance may send
| Projects the outcomes of payment requests, for an organization entitled
| to both. Nothing else may address a product: not another module, not a
| product, not Finance to another product.
*/

uses(IntegrationFixtures::class);

beforeEach(function () {
    $this->prepareIntegrationFixtures();
    foreach (['finance', 'projects'] as $catalog) {
        app(CatalogImporter::class)->import(json_decode((string) file_get_contents(base_path("tests/Fixtures/catalogs/{$catalog}.json")), true));
    }
    $this->finance = Application::query()->where('code', 'finance')->sole();
    $this->projects = Application::query()->where('code', 'projects')->sole();
    foreach ([$this->finance, $this->projects] as $application) {
        app(ManageEntitlements::class)->grantManual($this->organization, $application, null, 'Integration test');
    }
    $this->financeClient = app(ManageIntegration::class)->registerServiceClient($this->finance, 'Finance backend', null);
    $this->projectsClient = app(ManageIntegration::class)->registerServiceClient($this->projects, 'Projects backend', null);
});

function tokenFor(array $client, string $audience, ?string $organizationId = null): TestResponse
{
    return test()->withToken(test()->serviceToken('events.issue', $client))
        ->postJson(test()->apiUrl('event-tokens'), ['organization_id' => $organizationId ?? test()->organization->id, 'audience' => $audience]);
}

function projectsVerifier(string $audience = 'projects'): EventTokenVerifier
{
    return new EventTokenVerifier(PlatformHosts::url(PlatformHosts::auth()), (string) file_get_contents(Passport::keyPath('oauth-public.key')), $audience);
}

it('lets Finance report to Projects with a short-lived token bound to the organization', function () {
    $response = tokenFor($this->financeClient, 'projects')->assertOk()->assertJsonPath('expires_in', 300);

    $token = projectsVerifier()->verify('Bearer '.$response->json('token'));

    expect($token->sourceApp)->toBe('finance')
        ->and($token->organizationId)->toBe($this->organization->id)
        ->and(fn () => projectsVerifier('finance')->verify($response->json('token')))->toThrow(InvalidEventToken::class);
});

it('keeps products sending to Finance as before', function () {
    tokenFor($this->projectsClient, 'finance')->assertOk();
});

it('refuses every other route to a product', function () {
    // A product cannot address a product, itself included.
    tokenFor($this->projectsClient, 'projects')->assertStatus(422)->assertJsonPath('error', 'unknown_module');
    test()->withToken(test()->serviceToken('events.issue'))->postJson(test()->apiUrl('event-tokens'), ['organization_id' => test()->organization->id, 'audience' => 'projects'])
        ->assertStatus(422)->assertJsonPath('error', 'unknown_module');
    // Finance cannot address a product not listed for it.
    tokenFor($this->financeClient, 'retail')->assertStatus(422)->assertJsonPath('error', 'unknown_module');
});

it('refuses when the organization is not entitled to Projects or to Finance', function () {
    DB::table('entitlements')->where('organization_id', $this->organization->id)->where('application_id', $this->projects->id)->update(['revoked_at' => now()]);
    tokenFor($this->financeClient, 'projects')->assertNotFound()->assertJsonPath('error', 'not_entitled');

    DB::table('entitlements')->where('organization_id', $this->organization->id)->where('application_id', $this->projects->id)->update(['revoked_at' => null]);
    DB::table('entitlements')->where('organization_id', $this->organization->id)->where('application_id', $this->finance->id)->update(['revoked_at' => now()]);
    tokenFor($this->financeClient, 'projects')->assertNotFound()->assertJsonPath('error', 'not_entitled');
});

it('refuses an organization that is not active, or unknown', function () {
    DB::table('organizations')->where('id', $this->organization->id)->update(['status' => 'suspended']);
    tokenFor($this->financeClient, 'projects')->assertStatus(422)->assertJsonPath('error', 'organization_inactive');

    tokenFor($this->financeClient, 'projects', '00000000-0000-4000-8000-000000000000')->assertNotFound();
    expect(Organization::query()->count())->toBeGreaterThan(0);
});
