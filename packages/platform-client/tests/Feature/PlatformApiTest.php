<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tests\Fixtures\FakePlatform;
use Miles\PlatformClient\Tests\TestCase;

beforeEach(function () {
    $this->platform = (new FakePlatform)->install();
    $this->api = app(PlatformApi::class);
});

function tokenRequests(string $scope): int
{
    return collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/oauth/token') && $pair[0]['scope'] === $scope)->count();
}

it('reuses a service token until it is about to expire', function () {
    $this->api->organization('acme');
    $this->api->forget(FakePlatform::uuid('acme'));
    $this->api->organization('acme');

    expect(tokenRequests('access.read'))->toBe(1);

    $this->travel(3600 - 29)->seconds();
    $this->api->forget(FakePlatform::uuid('acme'));
    $this->api->organization('acme');

    expect(tokenRequests('access.read'))->toBe(2);
});

it('calls the back-channel with the auth host header and a bearer token', function () {
    $organization = $this->api->organization('acme');

    expect($organization->id)->toBe(FakePlatform::uuid('acme'))
        ->and($organization->defaultCurrency)->toBe('SSP');

    Http::assertSent(fn (Request $request) => $request->url() === TestCase::BACKCHANNEL.'/api/v1/organizations/acme'
        && $request->hasHeader('Host', 'auth.'.TestCase::ROOT)
        && str_starts_with($request->header('Authorization')[0], 'Bearer svc.access.read.'));
});

it('gets a fresh service token once when the platform refuses the cached one', function () {
    $this->api->organization('acme');
    $this->api->forget(FakePlatform::uuid('acme'));
    $this->platform->refuseNextApiCall = true;

    expect($this->api->organization('acme')->slug)->toBe('acme')
        ->and(tokenRequests('access.read'))->toBe(2);
});

it('treats 404 as not visible and other failures as unavailable', function () {
    expect(fn () => $this->api->organization('nobody'))->toThrow(NotVisible::class);

    $this->platform->down = true;
    expect(fn () => $this->api->organization('globex'))->toThrow(PlatformUnavailable::class);
});

it('issues one event token per call for a shared module', function () {
    expect($this->api->eventToken(FakePlatform::uuid('acme'), 'finance'))->toBe('evt.finance');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/v1/event-tokens')
        && $request['organization_id'] === FakePlatform::uuid('acme')
        && $request['audience'] === 'finance'
        && str_starts_with($request->header('Authorization')[0], 'Bearer svc.events.issue.'));
});

it('reports health with the health scope', function () {
    $this->api->reportHealth('degraded', ['database' => 'ok', 'queue' => 'degraded'], '1.2.0');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/v1/health')
        && $request['status'] === 'degraded'
        && $request['components'] === ['database' => 'ok', 'queue' => 'degraded']
        && $request['version'] === '1.2.0'
        && ! isset($request['message']));
});
