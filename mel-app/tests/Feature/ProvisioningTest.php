<?php

use App\Mel\Models\Dimension;
use App\Mel\Models\Settings;
use Illuminate\Support\Str;
use Miles\PlatformClient\Webhooks\Signature;

/**
 * Central's provisioning call (POST /mel/platform/tenants), signed as the
 * platform signs it.
 */
function provisionWorkspace(string $slug, string $key, array $organization = [])
{
    $body = json_encode([
        'step_id' => $key,
        'application' => 'mel',
        'organization' => $organization + [
            'id' => test()->platform()->organization($slug)['id'],
            'slug' => $slug,
            'display_name' => Str::headline($slug),
            'country_code' => 'SS',
            'default_currency' => 'SSP',
            'timezone' => 'Africa/Juba',
        ],
    ]);

    return test()->call('POST', 'http://product.internal/mel/platform/tenants', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_MILES_SIGNATURE' => Signature::header($body, 'whsec_test', time()),
        'HTTP_IDEMPOTENCY_KEY' => $key,
    ], $body);
}

it('sets up M&E when the platform provisions an organization', function () {
    provisionWorkspace('acme', 'step-1')->assertCreated();

    $settings = $this->asTenant('acme', fn () => Settings::query()->first());
    expect($settings)
        ->display_name->toBe('Acme')
        ->currency->toBe('SSP')
        ->timezone->toBe('Africa/Juba')
        ->and($this->asTenant('acme', fn () => Dimension::query()->orderBy('name')->pluck('name')->all()))->toBe(['Age group', 'Sex']);
});

it('keeps one settings row per organization when provisioned again', function () {
    provisionWorkspace('acme', 'step-1')->assertCreated();
    provisionWorkspace('acme', 'step-2', ['display_name' => 'Acme Renamed'])->assertCreated();

    expect(Settings::acrossTenants()->count())->toBe(1)
        ->and($this->asTenant('acme', fn () => Settings::query()->first())->display_name)->toBe('Acme Renamed')
        ->and($this->asTenant('acme', fn () => Dimension::query()->count()))->toBe(2);
});

it('refuses unsigned provisioning calls', function () {
    $this->postJson('http://product.internal/mel/platform/tenants', ['organization' => ['id' => (string) Str::uuid(), 'slug' => 'x']], ['Idempotency-Key' => 'k'])
        ->assertUnauthorized();
});
