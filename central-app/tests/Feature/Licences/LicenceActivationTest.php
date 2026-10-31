<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Licences\Actions\LicenceKeys;
use App\Modules\Licences\Models\LicenceKey;
use App\Modules\Licences\Notifications\ActivationCodeSent;
use App\Modules\Licences\Support\LicenceCodes;
use App\Modules\Licences\Support\LicenceSigner;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

const DEVICE = '7KQ2-M9XD-4FTA';
const PHARMACY = 'AB12-CD34';

beforeEach(function () {
    config(['platform.admin.idle_minutes' => 100_000]);
    $this->seed = LicenceSigner::newSeed();
    config(['platform.licences.signing_key' => $this->seed]);
});

function makeKey(array $details = []): string
{
    return app(LicenceKeys::class)->issue(1, $details, null)[0]['key'];
}

function activateUrl(): string
{
    return PlatformHosts::url(PlatformHosts::auth(), '/api/v1/licences/activate');
}

/** The licence a code carries, when it verifies with the public key the app would hold. */
function verifiedLicence(string $code, string $seed): ?array
{
    [$prefix, $body, $signature] = explode('.', $code);
    $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
    $public = substr(base64_decode((new LicenceSigner($seed))->publicKey()), -32);

    return $prefix === 'MP1' && sodium_crypto_sign_verify_detached($decode($signature), $decode($body), $public)
        ? json_decode($decode($body), true)
        : null;
}

it('makes keys people can type, kept only as a hash', function () {
    $key = makeKey(['customer_name' => 'Juba Pharmacy']);

    expect($key)->toMatch('/^MILES-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/');
    $stored = LicenceKey::query()->sole();
    expect($stored->key_hash)->toBe(LicenceCodes::hashKey($key))
        ->and($stored->key_hint)->toBe(substr($key, -4))
        ->and(LicenceKey::query()->where('key_hash', $key)->exists())->toBeFalse();
});

it('reads keys and codes as typed or read out', function () {
    $key = makeKey();
    $typed = strtolower(str_replace('-', ' ', $key));

    expect(LicenceCodes::hashKey($typed))->toBe(LicenceCodes::hashKey($key))
        ->and(LicenceCodes::device('7kq2m9xd4fta'))->toBe(DEVICE)
        ->and(LicenceCodes::pharmacy('ab12-cd34'))->toBe(PHARMACY)
        ->and(LicenceCodes::device('short'))->toBeNull();
});

it('activates a key on a PC with a signed code the app can check', function () {
    $key = makeKey(['duration_months' => 12]);

    $response = $this->postJson(activateUrl(), ['key' => $key, 'device' => DEVICE, 'pharmacy' => PHARMACY, 'name' => 'Juba Pharmacy', 'app_version' => '1.1.0'])
        ->assertOk();

    $licence = verifiedLicence($response->json('code'), $this->seed);
    expect($licence)->not->toBeNull()
        ->and($licence['device'])->toBe(DEVICE)
        ->and($licence['branch'])->toBe(PHARMACY)
        ->and($licence['name'])->toBe('Juba Pharmacy')
        ->and($licence['type'])->toBe('single')
        ->and($licence['expires'])->not->toBeNull();

    $activation = LicenceKey::query()->sole()->activation;
    expect($activation->id)->toBe($licence['id'])
        ->and($activation->channel)->toBe('app')
        ->and($activation->app_version)->toBe('1.1.0')
        ->and(AuditLog::query()->where('action', 'licence.activated')->exists())->toBeTrue();
});

it('gives the same PC its licence again, and refuses another PC', function () {
    $key = makeKey();
    $first = $this->postJson(activateUrl(), ['key' => $key, 'device' => DEVICE, 'pharmacy' => PHARMACY])->assertOk();
    $again = $this->postJson(activateUrl(), ['key' => $key, 'device' => strtolower(DEVICE), 'pharmacy' => PHARMACY])->assertOk();

    expect(verifiedLicence($again->json('code'), $this->seed)['id'])->toBe(verifiedLicence($first->json('code'), $this->seed)['id']);

    $this->postJson(activateUrl(), ['key' => $key, 'device' => 'ZZZZ-ZZZZ-ZZZZ', 'pharmacy' => PHARMACY])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'in_use')
        ->assertJsonValidationErrors('key');
});

it('moves a freed key to a new PC', function () {
    $key = makeKey();
    $this->postJson(activateUrl(), ['key' => $key, 'device' => DEVICE, 'pharmacy' => PHARMACY])->assertOk();

    app(LicenceKeys::class)->release(LicenceKey::query()->sole(), 'New PC', null);

    $this->postJson(activateUrl(), ['key' => $key, 'device' => 'ZZZZ-ZZZZ-ZZZZ', 'pharmacy' => PHARMACY])->assertOk();
    expect(LicenceKey::query()->sole()->activation->device_code)->toBe('ZZZZ-ZZZZ-ZZZZ');
});

it('refuses unknown and cancelled keys, and codes that are not codes', function () {
    $this->postJson(activateUrl(), ['key' => 'MILES-0000-0000-0000', 'device' => DEVICE, 'pharmacy' => PHARMACY])
        ->assertStatus(422)->assertJsonPath('reason', 'unknown_key');

    $key = makeKey();
    $this->postJson(activateUrl(), ['key' => $key, 'device' => 'nope', 'pharmacy' => PHARMACY])
        ->assertStatus(422)->assertJsonPath('reason', 'bad_device');
    $this->postJson(activateUrl(), ['key' => $key, 'device' => DEVICE, 'pharmacy' => '12'])
        ->assertStatus(422)->assertJsonPath('reason', 'bad_pharmacy');

    app(LicenceKeys::class)->revoke(LicenceKey::query()->sole(), 'Refunded', null);
    $this->postJson(activateUrl(), ['key' => $key, 'device' => DEVICE, 'pharmacy' => PHARMACY])
        ->assertStatus(422)->assertJsonPath('reason', 'revoked');
});

it('says so when no signing key is set', function () {
    config(['platform.licences.signing_key' => null]);
    $key = makeKey();

    $this->postJson(activateUrl(), ['key' => $key, 'device' => DEVICE, 'pharmacy' => PHARMACY])
        ->assertStatus(422)->assertJsonPath('reason', 'not_configured');
    expect(LicenceKey::query()->sole()->activation)->toBeNull();
});

it('activates from a phone on the public page and emails the code', function () {
    Notification::fake();
    $key = makeKey();

    $this->get(PlatformHosts::url(PlatformHosts::root(), '/activate?device=7kq2m9xd4fta&pharmacy=AB12CD34&name=Juba'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('public/activate')->where('device', DEVICE)->where('pharmacy', PHARMACY)->where('name', 'Juba'));

    $this->post(PlatformHosts::url(PlatformHosts::root(), '/activate'), [
        'key' => $key, 'device' => DEVICE, 'pharmacy' => PHARMACY, 'name' => 'Juba', 'email' => 'owner@example.com',
    ])->assertRedirect()->assertSessionHas('activation.code');

    Notification::assertSentOnDemand(ActivationCodeSent::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'owner@example.com'
        && verifiedLicence($n->code, $this->seed) !== null);
    expect(LicenceKey::query()->sole()->activation->channel)->toBe('web');
});

it('shows a wrong key on the public page by its field', function () {
    $this->post(PlatformHosts::url(PlatformHosts::root(), '/activate'), ['key' => 'MILES-0000-0000-0000', 'device' => DEVICE, 'pharmacy' => PHARMACY])
        ->assertSessionHasErrors('key');
});

it('lets licence staff make keys, activate for a customer, free and cancel them', function () {
    $staff = $this->staffWith(['billing_admin']);

    $this->actingAs($staff->user)->get($this->adminUrl('/licences'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/licences/index'));

    $this->actingAs($staff->user)->post($this->adminUrl('/licences'), ['count' => 2, 'customer_name' => 'Juba Pharmacy', 'duration_months' => 12])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('issued_keys.keys', fn ($keys) => count($keys) === 2);
    expect(LicenceKey::query()->count())->toBe(2);

    $key = session('issued_keys')['keys'][0];
    $this->actingAs($staff->user)->post($this->adminUrl('/licences/activate'), ['key' => $key, 'device' => DEVICE, 'pharmacy' => PHARMACY])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('signed_code.code', fn ($code) => verifiedLicence($code, $this->seed)['device'] === DEVICE);

    $model = LicenceKey::query()->where('key_hash', LicenceCodes::hashKey($key))->sole();
    expect($model->activation->channel)->toBe('staff');

    $this->actingAs($staff->user)->post($this->adminUrl("/licences/{$model->id}/release"), ['reason' => 'New PC'])->assertSessionHasNoErrors();
    expect($model->refresh()->activation)->toBeNull();

    $this->actingAs($staff->user)->post($this->adminUrl("/licences/{$model->id}/revoke"), ['reason' => 'Refunded'])->assertSessionHasNoErrors();
    expect($model->refresh()->status())->toBe('revoked');

    $this->actingAs($staff->user)->get($this->adminUrl('/licences?search='.substr($key, -4)))
        ->assertInertia(fn ($page) => $page->has('keys', 1));
});

it('keeps licences from staff without the permission', function () {
    $staff = $this->staffWith(['sales']);

    $this->actingAs($staff->user)->get($this->adminUrl('/licences'))->assertForbidden();
    $this->actingAs($staff->user)->post($this->adminUrl('/licences'), ['count' => 1])->assertForbidden();
});

it('makes a signing key whose public half the app can hold', function () {
    $this->artisan('licences:keygen')
        ->expectsOutputToContain('PLATFORM_LICENCE_SIGNING_KEY=')
        ->assertSuccessful();

    expect(strlen(base64_decode((new LicenceSigner($this->seed))->publicKey())))->toBe(44);
});
