<?php

use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_probe/request', fn () => [
        'ip' => request()->ip(),
        'secure' => request()->isSecure(),
    ]);
});

function probe(string $remoteAddr): array
{
    return test()->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get(PlatformHosts::url(PlatformHosts::root(), '_probe/request'))
        ->assertOk()
        ->json();
}

test('forwarded headers from a listed load balancer are honoured', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8, 192.168.1.5']);

    expect(probe('10.1.2.3'))->toBe(['ip' => '203.0.113.9', 'secure' => true])
        ->and(probe('192.168.1.5'))->toBe(['ip' => '203.0.113.9', 'secure' => true]);
});

test('anyone else cannot spoof their address or HTTPS', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8']);

    expect(probe('198.51.100.7'))->toBe(['ip' => '198.51.100.7', 'secure' => false]);
});

test('no proxy is trusted unless configured', function () {
    config(['trustedproxy.proxies' => null]);

    expect(probe('10.1.2.3'))->toBe(['ip' => '10.1.2.3', 'secure' => false]);
});
