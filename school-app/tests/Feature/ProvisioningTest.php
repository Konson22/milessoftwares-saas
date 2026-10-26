<?php

use App\Models\Workspace;
use Illuminate\Support\Str;
use Miles\PlatformClient\Webhooks\Signature;

function provisionWorkspace(string $slug, string $key, array $organization = [])
{
    $body = json_encode([
        'step_id' => $key,
        'application' => 'school',
        'organization' => $organization + [
            'id' => test()->platform()->organization($slug)['id'],
            'slug' => $slug,
            'display_name' => Str::headline($slug),
            'country_code' => 'SS',
            'default_currency' => 'SSP',
            'timezone' => 'Africa/Juba',
        ],
    ]);

    return test()->call('POST', 'http://product.internal/school/platform/tenants', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_MILES_SIGNATURE' => Signature::header($body, 'whsec_test', time()),
        'HTTP_IDEMPOTENCY_KEY' => $key,
    ], $body);
}

it('sets up a workspace when the platform provisions an organization', function () {
    provisionWorkspace('acme', 'step-1')->assertCreated();

    $workspace = $this->asTenant('acme', fn () => Workspace::current());
    expect($workspace)
        ->display_name->toBe('Acme')
        ->currency->toBe('SSP')
        ->timezone->toBe('Africa/Juba');
});

it('keeps one workspace per organization when provisioned again', function () {
    provisionWorkspace('acme', 'step-1')->assertCreated();
    provisionWorkspace('acme', 'step-2', ['display_name' => 'Acme Renamed'])->assertCreated();

    expect(Workspace::acrossTenants()->count())->toBe(1)
        ->and($this->asTenant('acme', fn () => Workspace::current())->display_name)->toBe('Acme Renamed');
});

it('refuses unsigned provisioning calls', function () {
    $this->postJson('http://product.internal/school/platform/tenants', ['organization' => ['id' => (string) Str::uuid(), 'slug' => 'x']], ['Idempotency-Key' => 'k'])
        ->assertUnauthorized();
});
