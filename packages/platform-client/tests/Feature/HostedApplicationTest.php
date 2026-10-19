<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Events\Outbox;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Testing\InteractsWithPlatform;
use Miles\PlatformClient\Tests\TestCase;
use Miles\PlatformClient\Webhooks\PlatformEventReceived;
use Miles\PlatformClient\Webhooks\Signature;

uses(InteractsWithPlatform::class);

/*
| One deployment serving two registry applications: pharmacy (primary) and
| retail (platform-client.hosted). Each is checked with its own clients.
*/

function retailUrl(string $slug, string $path = ''): string
{
    return test()->workspaceUrl($slug, $path, 'retail');
}

it('serves the primary application first, then the hosted ones', function () {
    $config = app(PlatformConfig::class);

    expect($config->applications())->toBe(['pharmacy', 'retail'])
        ->and($config->application())->toBe('pharmacy')
        ->and($config->runAs('retail', fn () => [$config->application(), $config->path(), $config->serviceClientId(), $config->routeName('login')]))
        ->toBe(['retail', 'retail', 'retail-service-client', 'platform.retail.login'])
        // Back to the primary afterwards.
        ->and([$config->application(), $config->serviceClientId(), $config->routeName('login')])
        ->toBe(['pharmacy', 'service-client', 'platform.login']);
});

it('refuses an application it does not serve and names the missing hosted value', function () {
    $config = app(PlatformConfig::class);

    expect(fn () => $config->runAs('school', fn () => null))->toThrow(RuntimeException::class, 'does not serve [school]');

    config(['platform-client.hosted.retail.sign_in.client_id' => null]);
    expect(fn () => $config->runAs('retail', fn () => $config->signInClientId()))
        ->toThrow(RuntimeException::class, 'PLATFORM_RETAIL_SIGN_IN_CLIENT_ID');
});

it('registers the sign-in, webhook and provisioning routes of each application under its path', function () {
    expect(Route::getRoutes()->getByName('platform.login')->uri())->toBe('pharmacy/auth/login')
        ->and(Route::getRoutes()->getByName('platform.retail.login')->uri())->toBe('retail/auth/login')
        ->and(Route::getRoutes()->getByName('platform.retail.callback')->uri())->toBe('retail/auth/callback')
        ->and(Route::getRoutes()->getByName('platform.retail.webhooks')->uri())->toBe('retail/platform/webhooks')
        ->and(Route::getRoutes()->getByName('platform.retail.provisioning')->uri())->toBe('retail/platform/tenants');
});

it('sends guests to the sign-in of the application they asked for', function () {
    $this->platform()->organization('acme');

    $this->get(retailUrl('acme'))->assertRedirect(retailUrl('acme', 'auth/login'));
    $this->get($this->workspaceUrl('acme'))->assertRedirect($this->workspaceUrl('acme', 'auth/login'));
});

it('signs in at a hosted application with its own sign-in client', function () {
    $this->platform()->organization('acme');

    $location = (string) $this->get(retailUrl('acme', 'auth/login'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('retail-sign-in-client')
        ->and($query['redirect_uri'])->toBe(retailUrl('acme', 'auth/callback'));
});

it('checks access per application: allowed in the pharmacy is not allowed in retail', function () {
    $this->actingAsMember('acme', ['pharmacy.dispense']);

    $this->get($this->workspaceUrl('acme'))->assertOk()->assertSee('pharmacy home');
    $this->get(retailUrl('acme'))->assertForbidden();
});

it('gives each application only its own permissions', function () {
    $user = $this->actingAsMember('acme', ['pharmacy.dispense']);
    $this->actingAsMember('acme', ['retail.sales.create'], user: $user, application: 'retail');

    $this->get(retailUrl('acme', 'sell'))->assertSee('can sell');
    $this->get($this->workspaceUrl('acme', 'dispense'))->assertSee('can dispense');

    // Each page's gate knows its own application's access only.
    $this->platform()->grant('acme', $user->getAuthIdentifier(), [['permissions' => ['retail.sales.create']]]);
    app(PlatformApi::class)->forget($this->platform()->organization('acme')['id']);
    $this->get($this->workspaceUrl('acme', 'dispense'))->assertSee('cannot dispense');
});

it('never serves one application\'s cached organization lookup to another', function () {
    // Entitled to the pharmacy, not to retail: the platform shows retail nothing.
    $this->platform()->hiddenFrom['retail'] = ['acme'];
    $user = $this->actingAsMember('acme', ['pharmacy.dispense']);
    $this->platform()->grant('acme', $user->getAuthIdentifier(), [['permissions' => ['retail.sales.create']]], application: 'retail');

    $this->get($this->workspaceUrl('acme'))->assertOk();
    $this->get(retailUrl('acme'))->assertNotFound();
});

it('records and delivers a hosted application\'s events as that application', function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->platform()->organization('acme');
    $outbox = app(Outbox::class);
    $config = app(PlatformConfig::class);

    // Delivered later by a queue worker, outside the request that recorded them.
    Queue::fake();
    $retail = $this->asTenant('acme', fn () => $config->runAs('retail', fn () => $outbox->record('finance', 'finance.invoice.issued', 1, 'retail-sale-1', ['total_minor' => 100])));
    $pharmacy = $this->asTenant('acme', fn () => $outbox->record('finance', 'finance.invoice.issued', 1, 'pharmacy-sale-1', ['total_minor' => 200]));
    $outbox->deliver($retail);
    $outbox->deliver($pharmacy);

    expect(DB::table('platform_outbox')->where('id', $retail)->value('application'))->toBe('retail')
        ->and(DB::table('platform_outbox')->where('id', $pharmacy)->value('application'))->toBeNull()
        ->and(array_column($this->platform()->eventTokens, 'application'))->toBe(['retail', 'pharmacy'])
        ->and(array_column($this->platform()->moduleEvents['finance'], 'source_app'))->toBe(['retail', 'pharmacy'])
        // Delivered as the primary again afterwards.
        ->and($config->application())->toBe('pharmacy');
});

it('holds an event of an application the deployment no longer serves', function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->platform()->organization('acme');
    $outbox = app(Outbox::class);

    Queue::fake();
    $id = $this->asTenant('acme', fn () => app(PlatformConfig::class)->runAs('retail', fn () => $outbox->record('finance', 'finance.invoice.issued', 1, 'retail-sale-1', ['total_minor' => 100])));
    config(['platform-client.hosted' => []]);

    $outbox->deliver($id);

    $row = DB::table('platform_outbox')->where('id', $id)->first();
    expect($row->status)->toBe('pending')
        ->and($row->last_error)->toContain('no longer serves')
        ->and($this->platform()->moduleEvents)->toBe([]);
});

it('verifies a hosted application\'s webhooks with its own secret', function () {
    Event::fake([PlatformEventReceived::class]);
    $body = json_encode(['event_id' => (string) Str::uuid(), 'event_type' => 'access.changed', 'organization_id' => (string) Str::uuid(), 'data' => []]);
    $post = fn (string $path, string $secret) => $this->call('POST', "http://pharmacy.internal/{$path}/platform/webhooks", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_MILES_SIGNATURE' => Signature::header($body, $secret, time()),
    ], $body);

    $post('retail', TestCase::WEBHOOK_SECRET)->assertUnauthorized();
    $post('pharmacy', TestCase::RETAIL_WEBHOOK_SECRET)->assertUnauthorized();
    $post('retail', TestCase::RETAIL_WEBHOOK_SECRET)->assertOk()->assertJson(['status' => 'received']);
});

it('provisions at each application\'s endpoint only for that application', function () {
    $provision = function (string $path, string $application, string $secret) {
        $body = json_encode(['application' => $application, 'organization' => ['id' => (string) Str::uuid(), 'slug' => 'acme']]);

        return $this->call('POST', "http://pharmacy.internal/{$path}/platform/tenants", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MILES_SIGNATURE' => Signature::header($body, $secret, time()),
            'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
        ], $body);
    };

    $provision('pharmacy', 'retail', TestCase::WEBHOOK_SECRET)->assertUnprocessable()->assertJson(['error' => 'wrong_application']);
    $provision('retail', 'retail', TestCase::RETAIL_WEBHOOK_SECRET)->assertCreated();
});
