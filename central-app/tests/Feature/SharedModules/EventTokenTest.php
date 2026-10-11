<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Miles\SharedModuleKit\Envelope;
use Miles\SharedModuleKit\EventTokenVerifier;
use Miles\SharedModuleKit\Inbox;
use Miles\SharedModuleKit\InboxResult;
use Miles\SharedModuleKit\InvalidEventToken;
use Miles\SharedModuleKit\Jwks;
use Psr\Clock\ClockInterface;
use Tests\Support\IntegrationFixtures;

uses(IntegrationFixtures::class);

beforeEach(function () {
    $this->prepareIntegrationFixtures();
    app(CatalogImporter::class)->import(json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/finance.json')), true));
    $this->finance = Application::query()->where('code', 'finance')->sole();
    app(ManageEntitlements::class)->grantManual($this->organization, $this->finance, null, 'Finance module');
});

function requestEventToken(array $input, string $scope = 'events.issue'): TestResponse
{
    return test()->withToken(test()->serviceToken($scope))->postJson(test()->apiUrl('event-tokens'), $input);
}

function verifierFor(string $module): EventTokenVerifier
{
    return new EventTokenVerifier(PlatformHosts::url(PlatformHosts::auth()), (string) file_get_contents(Passport::keyPath('oauth-public.key')), $module);
}

it('issues an organization-bound token a module can verify', function () {
    $response = requestEventToken(['organization_id' => $this->organization->id, 'audience' => 'finance'])
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('expires_in', 300);

    $token = verifierFor('finance')->verify('Bearer '.$response->json('token'));

    expect($token->sourceApp)->toBe('retail')
        ->and($token->organizationId)->toBe($this->organization->id);
});

it('cannot be used at another module', function () {
    $jwt = requestEventToken(['organization_id' => $this->organization->id, 'audience' => 'finance'])->json('token');

    expect(fn () => verifierFor('payroll')->verify($jwt))->toThrow(InvalidEventToken::class);
});

it('expires after five minutes', function () {
    $jwt = requestEventToken(['organization_id' => $this->organization->id, 'audience' => 'finance'])->json('token');

    $later = new class implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('+6 minutes');
        }
    };

    expect(fn () => (new EventTokenVerifier(PlatformHosts::url(PlatformHosts::auth()), (string) file_get_contents(Passport::keyPath('oauth-public.key')), 'finance', $later))->verify($jwt))
        ->toThrow(InvalidEventToken::class);
});

it('rejects tokens signed by anyone else', function () {
    $jwt = requestEventToken(['organization_id' => $this->organization->id, 'audience' => 'finance'])->json('token');
    $foreignPublicKey = openssl_pkey_get_details(openssl_pkey_get_private((string) file_get_contents(base_path('tests/Fixtures/foreign-signing-key.pem'))))['key'];

    expect(fn () => (new EventTokenVerifier(PlatformHosts::url(PlatformHosts::auth()), $foreignPublicKey, 'finance'))->verify($jwt))
        ->toThrow(InvalidEventToken::class);
});

it('refuses when the organization is not entitled to the module or the product', function () {
    $entitlement = DB::table('entitlements')->where('organization_id', $this->organization->id)->where('application_id', $this->finance->id)->value('id');
    DB::table('entitlements')->where('id', $entitlement)->update(['revoked_at' => now()]);

    requestEventToken(['organization_id' => $this->organization->id, 'audience' => 'finance'])
        ->assertNotFound()
        ->assertJsonPath('error', 'not_entitled');
});

it('refuses audiences that are not shared modules', function () {
    requestEventToken(['organization_id' => $this->organization->id, 'audience' => 'retail'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'unknown_module');
});

it('needs the events.issue scope', function () {
    requestEventToken(['organization_id' => $this->organization->id, 'audience' => 'finance'], 'access.read')->assertForbidden();
});

it('lets a product post a sale to Finance exactly once, end to end', function () {
    DB::unprepared((string) file_get_contents(base_path('../packages/shared-module-kit/schema/postgres.sql')));
    $inbox = new Inbox(DB::connection()->getPdo());
    $posted = 0;

    $send = function () use ($inbox, &$posted): InboxResult {
        // What the product does on every attempt: fresh token, fresh event id.
        $jwt = requestEventToken(['organization_id' => $this->organization->id, 'audience' => 'finance'])->json('token');
        $envelope = Envelope::fromArray([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'finance.payment.received',
            'schema_version' => 1,
            'organization_id' => $this->organization->id,
            'source_app' => 'retail',
            'source_transaction_id' => 'PAY-2026-0001',
            'occurred_at' => '2026-10-10T10:00:00+00:00',
            'payload' => ['payment_reference' => 'PAY-2026-0001', 'currency' => 'SSP', 'amount_minor' => 50000, 'method' => 'mobile_money'],
        ]);

        // What Finance does with each delivery.
        return $inbox->accept($envelope, verifierFor('finance')->verify($jwt), function () use (&$posted): void {
            $posted++;
        });
    };

    expect($send()->status)->toBe(InboxResult::ACCEPTED)
        ->and($send()->status)->toBe(InboxResult::DUPLICATE)
        ->and($send()->status)->toBe(InboxResult::DUPLICATE)
        ->and($posted)->toBe(1);
});

it('records health reports and shows stale services as unknown', function () {
    $this->withToken($this->serviceToken('health.write'))
        ->postJson($this->apiUrl('health'), ['status' => 'degraded', 'components' => ['database' => 'ok', 'queue' => 'degraded'], 'version' => '1.4.2', 'message' => 'Queue backlog'])
        ->assertStatus(202);

    $staff = $this->staffWith(['support_agent']);
    $retail = fn ($page) => collect($page->toArray()['props']['services'])->firstWhere('code', 'retail');

    $this->actingAs($staff->user)->get($this->adminUrl())->assertInertia(fn ($page) => expect($retail($page))->toMatchArray(['status' => 'degraded', 'version' => '1.4.2']) && true);

    $this->travel(6)->minutes();
    config(['platform.admin.idle_minutes' => 1000]);

    $this->actingAs($staff->user)->get($this->adminUrl())->assertInertia(fn ($page) => expect($retail($page)['status'])->toBe('unknown') && true);
});

it('validates health reports and needs the health.write scope', function () {
    $this->withToken($this->serviceToken('health.write'))->postJson($this->apiUrl('health'), ['status' => 'fine'])->assertStatus(422);
    $this->withToken($this->serviceToken('access.read'))->postJson($this->apiUrl('health'), ['status' => 'ok'])->assertForbidden();
});

it('rebuilds the platform key from the published JWKS', function () {
    $jwks = $this->get(route('openid.jwks'))->assertOk()->getContent();
    $jwt = requestEventToken(['organization_id' => $this->organization->id, 'audience' => 'finance'])->json('token');

    $fromJwks = openssl_pkey_get_details(openssl_pkey_get_public(Jwks::publicKeyPem((string) $jwks, Jwks::kidOf($jwt))));
    $actual = openssl_pkey_get_details(openssl_pkey_get_public((string) file_get_contents(Passport::keyPath('oauth-public.key'))));

    expect($fromJwks['key'])->toBe($actual['key']);
    expect(fn () => Jwks::publicKeyPem((string) $jwks, 'unknown-kid'))->toThrow(RuntimeException::class);
});
