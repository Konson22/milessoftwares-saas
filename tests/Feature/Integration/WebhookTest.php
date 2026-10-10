<?php

use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Modules\Integration\Jobs\SendWebhook;
use App\Modules\Integration\Models\WebhookDelivery;
use App\Modules\Integration\Support\WebhookSignature;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IntegrationFixtures;

uses(IntegrationFixtures::class);

const HOOK_URL = 'https://retail.example.test/platform/webhooks';

beforeEach(function () {
    $this->prepareIntegrationFixtures();
    $this->secret = app(ManageIntegration::class)->configureWebhook($this->retail, HOOK_URL, false, null);
});

// Laravel uses the first matching fake, so each test sets its own responses.
function productAccepts(): void
{
    Http::fake([HOOK_URL => Http::response(['ok' => true])]);
}

function sentHooks(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $request) => $request->url() === HOOK_URL)
        ->values()
        ->all();
}

it('signs and delivers access changes', function () {
    productAccepts();
    $member = $this->member();
    app(TenantContext::class)->run($this->organization, fn () => $this->grant($member, 'retail', 'cashier', Scope::branch($this->juba->id)));

    $delivery = WebhookDelivery::query()->where('event_type', 'access.changed')->sole();
    expect($delivery->status)->toBe('delivered')
        ->and($delivery->payload['data'])->toMatchArray(['application' => 'retail', 'membership_id' => $member->id, 'sub' => $member->user_id])
        ->and($delivery->payload['event_id'])->toBe($delivery->id);

    $request = sentHooks()[0];
    expect($request->header('Miles-Event-Id')[0])->toBe($delivery->id)
        ->and(WebhookSignature::verify($this->secret, $request->body(), $request->header(WebhookSignature::HEADER)[0]))->toBeTrue();
});

it('tells every entitled product when a member is removed or the organization is suspended', function () {
    productAccepts();
    $member = $this->member();
    app(TenantContext::class)->clear();

    $this->actingAs($this->owner->user)->put(PlatformHosts::url(PlatformHosts::workspace($this->organization->primarySlug->slug), "manage/members/{$member->id}/status"), ['status' => 'removed']);
    $this->actingAs($this->staffWith(['support_manager'])->user)->put(PlatformHosts::url(PlatformHosts::admin(), "organizations/{$this->organization->id}/status"), ['status' => 'suspended', 'reason' => 'Unpaid']);

    expect(WebhookDelivery::query()->pluck('event_type')->all())->toContain('membership.revoked', 'organization.suspended');
});

it('tells the product when its entitlement changes', function () {
    productAccepts();
    $entitlement = app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->sole());
    app(ManageEntitlements::class)->revoke($this->organization, $entitlement, $this->staffWith(['billing_admin']), 'Ended');

    expect(WebhookDelivery::query()->where('event_type', 'entitlement.changed')->sole()->payload['data'])->toBe(['application' => 'retail']);
});

it('retries failed deliveries with backoff, then gives up', function () {
    Http::fake([HOOK_URL => Http::response('down', 503)]);
    Queue::fake();

    $member = $this->member();
    app(TenantContext::class)->run($this->organization, fn () => $this->grant($member, 'retail', 'cashier', Scope::branch($this->juba->id)));
    $delivery = WebhookDelivery::query()->sole();

    (new SendWebhook($delivery->id))->handle();

    expect($delivery->refresh()->status)->toBe('pending')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_response_code)->toBe(503)
        ->and($delivery->next_attempt_at->diffInSeconds(now(), true))->toBeGreaterThan(55);
    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->delay === 60);

    foreach (range(2, count(SendWebhook::BACKOFF) + 1) as $_) {
        (new SendWebhook($delivery->id))->handle();
    }

    expect($delivery->refresh()->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(count(SendWebhook::BACKOFF) + 1);
});

it('lets staff redeliver a failed webhook', function () {
    Http::fake([HOOK_URL => Http::sequence()->push('down', 500)->whenEmpty(Http::response(['ok' => true]))]);
    Queue::fake();
    $member = $this->member();
    app(TenantContext::class)->run($this->organization, fn () => $this->grant($member, 'retail', 'cashier', Scope::branch($this->juba->id)));
    $delivery = WebhookDelivery::query()->sole();
    $delivery->forceFill(['status' => 'failed'])->save();

    $this->actingAs($this->staffWith(['catalog_admin'])->user)
        ->post(PlatformHosts::url(PlatformHosts::admin(), "applications/{$this->retail->id}/integration/deliveries/{$delivery->id}/redeliver"))
        ->assertRedirect();

    expect($delivery->refresh()->status)->toBe('pending');
    Queue::assertPushed(SendWebhook::class);
});

it('verifies signatures strictly', function () {
    $body = '{"event_id":"e1"}';
    $now = 1_800_000_000;
    $header = WebhookSignature::sign('secret', $body, $now);

    expect(WebhookSignature::verify('secret', $body, $header, $now))->toBeTrue()
        ->and(WebhookSignature::verify('secret', $body.' ', $header, $now))->toBeFalse()
        ->and(WebhookSignature::verify('other', $body, $header, $now))->toBeFalse()
        ->and(WebhookSignature::verify('secret', $body, $header, $now + 301))->toBeFalse()
        ->and(WebhookSignature::verify('secret', $body, 'garbage', $now))->toBeFalse();
});
