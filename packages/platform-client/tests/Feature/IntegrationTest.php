<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\Http\Middleware\RequireIntegrationToken;
use Miles\PlatformClient\Integration\IntegrationClient;
use Miles\PlatformClient\Integration\IntegrationStatus;
use Miles\PlatformClient\Testing\InteractsWithPlatform;
use Miles\SharedModuleKit\EventToken;

/*
| Reading a paired product's integration API (ADR-0019 §9): the calling
| side (IntegrationClient) and the receiving side (platform.integration).
| The test application is "pharmacy"; its pair here is "mel".
*/

uses(InteractsWithPlatform::class);

const PAIR_URL = 'https://mel.internal/mel/integration/v1';

beforeEach(function () {
    config(['platform-client.integrations.mel' => PAIR_URL]);
    $this->org = $this->platform()->organization('acme')['id'];
});

function pairAnswers(array $responses): void
{
    Http::fake(['mel.internal/*' => Http::sequence($responses)]);
}

it('reads with a platform token for the organization and the acting member, and a correlation id', function () {
    pairAnswers([Http::response(['data' => [['id' => 'r1']]])]);

    $result = app(IntegrationClient::class)->get('mel', $this->org, 'projects/p1/verified-results', ['from' => '2026-01-01'], 'user-1');

    expect($result->status)->toBe(IntegrationStatus::Ok)
        ->and($result->data['data'][0]['id'])->toBe('r1')
        ->and($this->platform()->integrationTokens)->toBe([['organization_id' => $this->org, 'audience' => 'mel', 'acting_user' => 'user-1', 'application' => 'pharmacy']]);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), PAIR_URL.'/projects/p1/verified-results?from=2026-01-01')
        && $request->hasHeader('Authorization', 'Bearer fake-integration-token')
        && $request->header('X-Correlation-Id')[0] === $result->correlationId);
});

it('tells each failure apart, and never answers "nothing there" for a read that did not happen', function () {
    $client = app(IntegrationClient::class);

    config(['platform-client.integrations.mel' => null]);
    expect($client->get('mel', $this->org, 'x')->status)->toBe(IntegrationStatus::NotConfigured);
    config(['platform-client.integrations.mel' => PAIR_URL]);

    $this->platform()->notEntitledTo = ['mel'];
    expect($client->get('mel', $this->org, 'x')->status)->toBe(IntegrationStatus::NotEntitled);
    $this->platform()->notEntitledTo = [];

    $this->platform()->refuseIntegration = 'entitlement_expired';
    $expired = $client->get('mel', $this->org, 'x');
    expect($expired->status)->toBe(IntegrationStatus::NotEntitled)->and($expired->error)->toBe('entitlement_expired');

    $this->platform()->refuseIntegration = 'not_member';
    $refused = $client->get('mel', $this->org, 'x', [], 'stranger');
    expect($refused->status)->toBe(IntegrationStatus::Denied)->and($refused->error)->toBe('not_member');
    $this->platform()->refuseIntegration = null;

    pairAnswers([
        Http::response(['error' => 'forbidden'], 403),
        Http::response(['error' => 'unknown_project'], 404),
        Http::response(['error' => 'invalid'], 422),
        Http::response('oops', 500),
    ]);
    expect($client->get('mel', $this->org, 'x')->status)->toBe(IntegrationStatus::Denied)
        ->and(($notFound = $client->get('mel', $this->org, 'x'))->status)->toBe(IntegrationStatus::NotFound)
        ->and($notFound->error)->toBe('unknown_project')
        ->and($notFound->data)->toBe([])
        ->and($client->get('mel', $this->org, 'x')->status)->toBe(IntegrationStatus::Invalid)
        ->and($client->get('mel', $this->org, 'x')->status)->toBe(IntegrationStatus::Unavailable);
});

it('retries once after a gateway error or a dropped connection, and stops there', function () {
    $client = app(IntegrationClient::class);

    pairAnswers([Http::response('', 503), Http::response(['data' => []]), Http::response('', 502), Http::response('', 503)]);

    expect($client->get('mel', $this->org, 'x')->status)->toBe(IntegrationStatus::Ok)
        ->and($client->get('mel', $this->org, 'x')->status)->toBe(IntegrationStatus::Unavailable);
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'mel.internal')))->toHaveCount(4);
});

it('reports a timeout as a timeout', function () {
    Http::fake(['mel.internal/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 3001 milliseconds')]);

    $result = app(IntegrationClient::class)->get('mel', $this->org, 'x');

    expect($result->status)->toBe(IntegrationStatus::Timeout)->and($result->status->transient())->toBeTrue();
});

describe('receiving', function () {
    beforeEach(function () {
        Route::middleware('platform.integration:mel')->get('/pharmacy/integration/v1/ping', function () {
            /** @var EventToken $token */
            $token = request()->attributes->get(RequireIntegrationToken::TOKEN);

            return ['organization' => $token->organizationId, 'acting_user' => $token->actingUser, 'source' => $token->sourceApp];
        });
    });

    it('accepts a platform read token for this application from the allowed product', function () {
        $token = $this->platform()->integrationToken($this->org, 'mel', 'pharmacy', 'User-1');

        $this->withToken($token)->withHeader('X-Correlation-Id', 'corr-12345678')->getJson('/pharmacy/integration/v1/ping')
            ->assertOk()
            ->assertJson(['organization' => $this->org, 'acting_user' => 'user-1', 'source' => 'mel'])
            ->assertHeader('X-Correlation-Id', 'corr-12345678');
    });

    it('refuses anything else', function (?array $token, int $status, string $error) {
        $jwt = $token === null ? '' : $this->platform()->integrationToken($this->org, $token[0], $token[1], null, $token[2] ?? []);

        $this->withToken($jwt)->getJson('/pharmacy/integration/v1/ping')->assertStatus($status)->assertJsonPath('error', $error)->assertHeader('X-Correlation-Id');
    })->with([
        'no token' => [null, 401, 'invalid_token'],
        'another audience' => [['mel', 'finance'], 401, 'invalid_token'],
        'an event token' => [['mel', 'pharmacy', ['scope' => 'events.write']], 401, 'invalid_token'],
        'expired' => [['mel', 'pharmacy', ['expires' => '-2 minutes']], 401, 'invalid_token'],
        'another issuer' => [['mel', 'pharmacy', ['issuer' => 'https://evil.example']], 401, 'invalid_token'],
        'a product not allowed here' => [['finance', 'pharmacy'], 403, 'source_not_allowed'],
    ]);
});
