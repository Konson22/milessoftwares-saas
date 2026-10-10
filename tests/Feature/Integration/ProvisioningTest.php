<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Modules\Integration\Models\WebhookEndpoint;
use App\Modules\Integration\Support\WebhookSignature;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Provisioning\Jobs\ProvisionTenant;
use App\Modules\Provisioning\Models\ProvisioningRun;
use App\Modules\Provisioning\Models\ProvisioningStep;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IntegrationFixtures;

uses(IntegrationFixtures::class);

const TENANTS_URL = 'https://retail.example.test/platform/tenants';

beforeEach(function () {
    $this->prepareIntegrationFixtures();
    $this->retail->forceFill(['provisioning_method' => 'sync_api', 'integration_endpoint' => 'https://retail.example.test/platform'])->save();
    $this->secret = app(ManageIntegration::class)->configureWebhook($this->retail, 'https://retail.example.test/platform/webhooks', false, null);
    $this->billing = $this->staffWith(['billing_admin']);
    $this->newOrganization = Organization::factory()->create();
});

function entitleNewOrganization(): void
{
    app(ManageEntitlements::class)->grantManual(test()->newOrganization, test()->retail, test()->billing, 'Pilot');
}

function stepFor(Organization $organization): ?ProvisioningStep
{
    return ProvisioningStep::acrossTenants()->where('organization_id', $organization->id)->first();
}

it('creates the tenant in the product when the organization is entitled', function () {
    Http::fake([TENANTS_URL => Http::response(['tenant_id' => 'T-1'], 201), '*' => Http::response()]);

    entitleNewOrganization();

    expect(stepFor($this->newOrganization)->status)->toBe('succeeded');

    $call = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn (HttpRequest $request) => $request->url() === TENANTS_URL);
    expect($call->header('Idempotency-Key')[0])->toBe(stepFor($this->newOrganization)->id)
        ->and($call['organization']['id'])->toBe($this->newOrganization->id)
        ->and(WebhookSignature::verify($this->secret, $call->body(), $call->header(WebhookSignature::HEADER)[0]))->toBeTrue();
});

it('never provisions the same tenant twice', function () {
    Http::fake([TENANTS_URL => Http::response([], 201), '*' => Http::response()]);

    entitleNewOrganization();
    entitleNewOrganization();

    expect(ProvisioningStep::acrossTenants()->where('organization_id', $this->newOrganization->id)->count())->toBe(1);
    Http::assertSentCount(1 + 2);
});

it('waits for asynchronous products to report back', function () {
    Http::fake([TENANTS_URL => Http::response([], 202), '*' => Http::response()]);

    entitleNewOrganization();
    $step = stepFor($this->newOrganization);
    expect($step->status)->toBe('running');

    $this->withToken($this->serviceToken('provisioning.write'))
        ->postJson($this->apiUrl("provisioning/steps/{$step->id}/complete"), ['status' => 'succeeded'])
        ->assertOk();

    expect($step->refresh()->status)->toBe('succeeded')
        ->and(ProvisioningRun::acrossTenants()->find($step->provisioning_run_id)->status)->toBe('succeeded');
});

it('only lets the owning product complete its step', function () {
    Http::fake([TENANTS_URL => Http::response([], 202), '*' => Http::response()]);
    entitleNewOrganization();
    $step = stepFor($this->newOrganization);

    $definition = json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true);
    $definition['application'] = ['code' => 'pharmacy', 'name' => 'Pharmacy', 'category' => 'health', 'kind' => 'standalone', 'status' => 'available'];
    app(CatalogImporter::class)->import($definition);
    $pharmacy = app(ManageIntegration::class)->registerServiceClient(Application::query()->where('code', 'pharmacy')->sole(), 'Pharmacy', null);

    $this->withToken($this->serviceToken('provisioning.write', $pharmacy))
        ->postJson($this->apiUrl("provisioning/steps/{$step->id}/complete"), ['status' => 'succeeded'])
        ->assertNotFound();

    expect($step->refresh()->status)->toBe('running');
});

it('retries failures and lets staff retry after giving up', function () {
    // Six failed attempts exhaust the backoff; the staff retry then succeeds.
    $responses = Http::sequence();
    foreach (range(1, 6) as $_) {
        $responses->push('err', 500);
    }
    Http::fake([TENANTS_URL => $responses->push([], 201), '*' => Http::response()]);
    Queue::fake();

    entitleNewOrganization();
    $step = stepFor($this->newOrganization);
    $job = fn () => app(TenantContext::class)->clear() ?? (new ProvisionTenant($this->newOrganization->id, $step->id))->handle(app(TenantContext::class));

    $job();
    expect($step->refresh()->status)->toBe('pending')->and($step->attempts)->toBe(1);

    foreach (range(1, 5) as $_) {
        $job();
    }
    expect($step->refresh()->status)->toBe('failed');

    $this->actingAs($this->staffWith(['catalog_admin'])->user)
        ->post(PlatformHosts::url(PlatformHosts::admin(), "applications/{$this->retail->id}/integration/provisioning/{$step->id}/retry"))
        ->assertRedirect();
    Queue::assertPushed(ProvisionTenant::class);

    $job();
    expect($step->refresh()->status)->toBe('succeeded');
});

it('fails clearly when the product has no integration secret', function () {
    WebhookEndpoint::query()->delete();
    Http::fake();

    entitleNewOrganization();

    expect(stepFor($this->newOrganization)->status)->toBe('failed')
        ->and(stepFor($this->newOrganization)->last_error)->toContain('no active integration endpoint secret');
});
