<?php

use App\Support\Hosts\HostType;
use App\Support\Hosts\PlatformHosts;

beforeEach(fn () => config(['platform.root_domain' => 'milessoftwares.com', 'app.url' => 'https://milessoftwares.com']));

it('classifies platform hosts', function (string $host, HostType $type) {
    expect(PlatformHosts::classify($host)['type'])->toBe($type);
})->with([
    ['milessoftwares.com', HostType::Marketing],
    ['MilesSoftwares.com.', HostType::Marketing],
    ['www.milessoftwares.com', HostType::Www],
    ['auth.milessoftwares.com', HostType::Auth],
    ['app.milessoftwares.com', HostType::App],
    ['admin.milessoftwares.com', HostType::Admin],
    ['manggala.milessoftwares.com', HostType::Workspace],
    ['api.milessoftwares.com', HostType::Reserved],
    ['a.b.milessoftwares.com', HostType::Unknown],
    ['evilmilessoftwares.com', HostType::Unknown],
    ['milessoftwares.com.evil.io', HostType::Unknown],
    ['ab.milessoftwares.com', HostType::Unknown],
]);

it('builds absolute URLs on platform hosts', function () {
    expect(PlatformHosts::url(PlatformHosts::workspace('manggala'), '/retail'))->toBe('https://manggala.milessoftwares.com/retail')
        ->and(PlatformHosts::url(PlatformHosts::root()))->toBe('https://milessoftwares.com');
});

it('keeps a non-standard port from APP_URL', function () {
    config(['app.url' => 'http://milessoftwares.localhost:8000', 'platform.root_domain' => 'milessoftwares.localhost']);

    expect(PlatformHosts::url(PlatformHosts::auth(), 'login'))->toBe('http://auth.milessoftwares.localhost:8000/login');
});
