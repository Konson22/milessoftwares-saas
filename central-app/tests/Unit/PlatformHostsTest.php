<?php

use App\Support\Hosts\HostType;
use App\Support\Hosts\PlatformHosts;

beforeEach(fn () => config(['platform.root_domain' => 'milesoftwares.com', 'app.url' => 'https://milesoftwares.com']));

it('classifies platform hosts', function (string $host, HostType $type) {
    expect(PlatformHosts::classify($host)['type'])->toBe($type);
})->with([
    ['milesoftwares.com', HostType::Marketing],
    ['MileSoftwares.com.', HostType::Marketing],
    ['www.milesoftwares.com', HostType::Www],
    ['auth.milesoftwares.com', HostType::Auth],
    ['app.milesoftwares.com', HostType::App],
    ['admin.milesoftwares.com', HostType::Admin],
    ['manggala.milesoftwares.com', HostType::Workspace],
    ['api.milesoftwares.com', HostType::Reserved],
    ['a.b.milesoftwares.com', HostType::Unknown],
    ['evilmilesoftwares.com', HostType::Unknown],
    ['milesoftwares.com.evil.io', HostType::Unknown],
    ['ab.milesoftwares.com', HostType::Unknown],
]);

it('builds absolute URLs on platform hosts', function () {
    expect(PlatformHosts::url(PlatformHosts::workspace('manggala'), '/retail'))->toBe('https://manggala.milesoftwares.com/retail')
        ->and(PlatformHosts::url(PlatformHosts::root()))->toBe('https://milesoftwares.com');
});

it('keeps a non-standard port from APP_URL', function () {
    config(['app.url' => 'http://milesoftwares.localhost:8000', 'platform.root_domain' => 'milesoftwares.localhost']);

    expect(PlatformHosts::url(PlatformHosts::auth(), 'login'))->toBe('http://auth.milesoftwares.localhost:8000/login');
});
