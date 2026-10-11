<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Tests\Fixtures\FakePlatform;
use Miles\PlatformClient\Tests\TestCase;
use Miles\PlatformClient\Webhooks\PlatformEventReceived;
use Miles\PlatformClient\Webhooks\Signature;

function webhook(TestCase $test, array $event, ?string $signature = null, ?int $timestamp = null)
{
    $body = json_encode($event);
    $signature ??= Signature::header($body, TestCase::WEBHOOK_SECRET, $timestamp ?? time());

    return $test->call('POST', 'http://pharmacy.internal/platform/webhooks', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_MILES_SIGNATURE' => $signature,
    ], $body);
}

function accessChanged(?string $eventId = null): array
{
    return [
        'event_id' => $eventId ?? (string) Str::uuid(),
        'event_type' => 'access.changed',
        'schema_version' => 1,
        'occurred_at' => now()->toIso8601String(),
        'organization_id' => FakePlatform::uuid('acme'),
        'data' => ['application' => 'pharmacy', 'membership_id' => 'm-1', 'sub' => FakePlatform::subject()],
    ];
}

it('accepts a signed event once and tells the product', function () {
    Event::fake([PlatformEventReceived::class]);
    $event = accessChanged();

    webhook($this, $event)->assertOk()->assertJson(['status' => 'received']);

    Event::assertDispatched(PlatformEventReceived::class, fn (PlatformEventReceived $e) => $e->eventId === $event['event_id']
        && $e->type === 'access.changed'
        && $e->organizationId === FakePlatform::uuid('acme')
        && $e->data['sub'] === FakePlatform::subject());
});

it('drops a duplicate delivery of the same event', function () {
    Event::fake([PlatformEventReceived::class]);
    $event = accessChanged();

    webhook($this, $event)->assertOk();
    webhook($this, $event)->assertOk()->assertJson(['status' => 'duplicate']);

    Event::assertDispatchedTimes(PlatformEventReceived::class, 1);
    expect(DB::table('platform_webhook_events')->count())->toBe(1);
});

it('refuses bad signatures', function (callable $sign) {
    Event::fake([PlatformEventReceived::class]);
    $event = accessChanged();

    webhook($this, $event, signature: $sign(json_encode($event)))->assertUnauthorized();

    Event::assertNotDispatched(PlatformEventReceived::class);
})->with([
    'missing' => [fn () => ''],
    'wrong secret' => [fn ($body) => Signature::header($body, 'whsec_other', time())],
    'older than five minutes (replay)' => [fn ($body) => Signature::header($body, TestCase::WEBHOOK_SECRET, time() - 301)],
    'another body' => [fn () => Signature::header('{"event_id":"x"}', TestCase::WEBHOOK_SECRET, time())],
    'garbage' => [fn () => 't=abc,v1=zz'],
]);

it('rejects a signed body that is not an event', function () {
    webhook($this, ['event_id' => 'not-a-uuid', 'event_type' => 'access.changed'])->assertStatus(422);
});

it('keeps the event retryable when the product fails to handle it', function () {
    $event = accessChanged();
    $fail = true;
    Event::listen(PlatformEventReceived::class, function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('listener failed');
        }
    });

    webhook($this, $event)->assertServerError();
    expect(DB::table('platform_webhook_events')->count())->toBe(0);

    $fail = false;
    webhook($this, $event)->assertOk()->assertJson(['status' => 'received']);
});

it('drops cached access for the organization', function () {
    $platform = (new FakePlatform)->install();
    $platform->grant('acme', FakePlatform::subject(), [FakePlatform::role('pharmacist', ['pharmacy.dispense'])]);
    $api = app(PlatformApi::class);

    expect($api->effectiveAccess(FakePlatform::uuid('acme'), FakePlatform::subject())->allowed)->toBeTrue();
    $platform->grant('acme', FakePlatform::subject(), allowed: false);
    expect($api->effectiveAccess(FakePlatform::uuid('acme'), FakePlatform::subject())->allowed)->toBeTrue(); // cached

    webhook($this, accessChanged())->assertOk();

    expect($api->effectiveAccess(FakePlatform::uuid('acme'), FakePlatform::subject())->allowed)->toBeFalse();
});
