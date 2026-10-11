<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Authorization\Actions\RevokeRole;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Modules\Integration\Support\IntegrationEvents;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\IntegrationFixtures;

uses(IntegrationFixtures::class);

beforeEach(function () {
    $this->prepareIntegrationFixtures();

    $this->cashier = $this->member();
    $this->assignment = app(TenantContext::class)->run($this->organization, fn () => $this->grant($this->cashier, 'retail', 'cashier', Scope::branch($this->juba->id)));
    app(TenantContext::class)->clear();
});

function effectiveAccess(string $token, string $organizationId, string $subject): TestResponse
{
    return test()->withToken($token)->getJson(test()->apiUrl("organizations/{$organizationId}/members/{$subject}/effective-access"));
}

it('answers who may do what, where, for the calling application', function () {
    effectiveAccess($this->serviceToken(), $this->organization->id, $this->cashier->user_id)
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=60, private')
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('application.code', 'retail')
        ->assertJsonPath('entitlement.active', true)
        ->assertJsonPath('membership.status', 'active')
        ->assertJsonPath('roles.0.role', 'cashier')
        ->assertJsonPath('roles.0.scope', ['scope_type' => 'branch', 'branch_id' => $this->juba->id])
        ->assertJsonPath('roles.0.permissions', ['retail.sales.create']);
});

it('says no, with reasons, for members without a role and for suspended members', function () {
    $plain = $this->member();
    app(TenantContext::class)->clear();

    effectiveAccess($this->serviceToken(), $this->organization->id, $plain->user_id)
        ->assertJsonPath('allowed', false)
        ->assertJsonPath('roles', []);

    app(TenantContext::class)->run($this->organization, fn () => $this->cashier->forceFill(['status' => MembershipStatus::Suspended])->save());
    app(IntegrationEvents::class)->membershipStatusChanged($this->organization->id, $this->cashier->id, $this->cashier->user_id, 'suspended');

    effectiveAccess($this->serviceToken(), $this->organization->id, $this->cashier->user_id)
        ->assertJsonPath('allowed', false)
        ->assertJsonPath('membership.status', 'suspended')
        ->assertJsonPath('roles', []);
});

it('stops serving cached access as soon as a role is revoked', function () {
    $token = $this->serviceToken();
    effectiveAccess($token, $this->organization->id, $this->cashier->user_id)->assertJsonPath('allowed', true);

    app(TenantContext::class)->run($this->organization, fn () => app(RevokeRole::class)->handle($this->owner, $this->assignment->refresh()));
    app(TenantContext::class)->clear();

    effectiveAccess($token, $this->organization->id, $this->cashier->user_id)
        ->assertJsonPath('allowed', false)
        ->assertJsonPath('roles', []);
});

it('resolves a workspace slug to the organization', function () {
    $this->withToken($this->serviceToken())
        ->getJson($this->apiUrl('organizations/'.$this->organization->primarySlug->slug))
        ->assertOk()
        ->assertJsonPath('id', $this->organization->id)
        ->assertJsonPath('status', 'active');
});

it('hides organizations that never used the calling application', function () {
    $other = Organization::factory()->create();

    effectiveAccess($this->serviceToken(), $other->id, $this->cashier->user_id)->assertNotFound();
    $this->withToken($this->serviceToken())->getJson($this->apiUrl('organizations/'.$other->primarySlug->slug))->assertNotFound();
});

it('limits every answer to the application that owns the token', function () {
    $definition = json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true);
    $definition['application'] = ['code' => 'pharmacy', 'name' => 'Pharmacy', 'category' => 'health', 'kind' => 'standalone', 'status' => 'available'];
    app(CatalogImporter::class)->import($definition);
    $pharmacyClient = app(ManageIntegration::class)->registerServiceClient(Application::query()->where('code', 'pharmacy')->sole(), 'Pharmacy backend', null);

    // The organization uses retail only, so pharmacy cannot see it at all.
    effectiveAccess($this->serviceToken(client: $pharmacyClient), $this->organization->id, $this->cashier->user_id)->assertNotFound();
});

it('rejects missing, invalid and under-scoped tokens', function () {
    $this->getJson($this->apiUrl("organizations/{$this->organization->id}/members/{$this->cashier->user_id}/effective-access"))
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token');

    effectiveAccess('not-a-token', $this->organization->id, $this->cashier->user_id)->assertUnauthorized();

    effectiveAccess($this->serviceToken('provisioning.write'), $this->organization->id, $this->cashier->user_id)
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
});

it('stops accepting tokens of a disabled client', function () {
    $token = $this->serviceToken();
    $rowId = DB::table('application_clients')->where('oauth_client_ref', $this->serviceClient['client_id'])->value('id');

    app(ManageIntegration::class)->disableClient($this->retail, $rowId, null);

    effectiveAccess($token, $this->organization->id, $this->cashier->user_id)->assertUnauthorized();
});
