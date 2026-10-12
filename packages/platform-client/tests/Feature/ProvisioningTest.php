<?php

use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Provisioning\ProvisionsTenants;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Miles\PlatformClient\Tests\Fixtures\FakePlatform;
use Miles\PlatformClient\Tests\Fixtures\Prescription;
use Miles\PlatformClient\Tests\TestCase;
use Miles\PlatformClient\Webhooks\Signature;

/**
 * Records what it was asked to do; optionally fails.
 */
class RecordingProvisioner implements ProvisionsTenants
{
    public array $calls = [];

    public bool $fail = false;

    public function provision(Organization $organization): void
    {
        $this->calls[] = [$organization->id, app(CurrentTenant::class)->id()];

        // Tenant-owned setup lands in the right organization.
        Prescription::query()->create(['reference' => 'welcome-'.$organization->slug]);

        if ($this->fail) {
            throw new RuntimeException('provisioning failed');
        }
    }
}

beforeEach(function () {
    $this->provisioner = new RecordingProvisioner;
    $this->app->instance(ProvisionsTenants::class, $this->provisioner);
});

function provision(TestCase $test, ?string $key = 'step-1', array $overrides = [], ?string $signature = null)
{
    $body = json_encode($overrides + [
        'step_id' => 'step-1',
        'application' => 'pharmacy',
        'organization' => [
            'id' => FakePlatform::uuid('acme'),
            'slug' => 'acme',
            'display_name' => 'Acme Pharmacy',
            'country_code' => 'SS',
            'default_currency' => 'SSP',
            'timezone' => 'Africa/Juba',
        ],
        'callback_url' => TestCase::ISSUER.'/api/v1/provisioning/steps/step-1/complete',
    ]);

    return $test->call('POST', 'http://pharmacy.internal/pharmacy/platform/tenants', [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_MILES_SIGNATURE' => $signature ?? Signature::header($body, TestCase::WEBHOOK_SECRET, time()),
        'HTTP_IDEMPOTENCY_KEY' => $key,
    ]), $body);
}

it('provisions an organization inside its tenant', function () {
    provision($this)->assertCreated()->assertJson(['status' => 'provisioned']);

    expect($this->provisioner->calls)->toBe([[FakePlatform::uuid('acme'), FakePlatform::uuid('acme')]])
        ->and(Prescription::acrossTenants()->value('organization_id'))->toBe(FakePlatform::uuid('acme'));
});

it('never provisions the same idempotency key twice', function () {
    provision($this)->assertCreated();
    provision($this)->assertOk()->assertJson(['status' => 'already_provisioned']);

    expect($this->provisioner->calls)->toHaveCount(1)
        ->and(Prescription::acrossTenants()->count())->toBe(1);
});

it('rolls back a failed provisioning so the retry starts clean', function () {
    $this->provisioner->fail = true;
    provision($this)->assertServerError();

    expect(DB::table('platform_provisioned_tenants')->count())->toBe(0)
        ->and(Prescription::acrossTenants()->count())->toBe(0);

    $this->provisioner->fail = false;
    provision($this)->assertCreated();
    expect(Prescription::acrossTenants()->count())->toBe(1);
});

it('refuses unsigned, keyless or foreign requests', function () {
    provision($this, signature: 't=1,v1=00')->assertUnauthorized();
    provision($this, key: null)->assertStatus(400);
    provision($this, overrides: ['application' => 'retail'])->assertStatus(422);
    provision($this, overrides: ['organization' => ['id' => 'nope', 'slug' => 'acme']])->assertStatus(422);

    expect($this->provisioner->calls)->toBe([]);
});
