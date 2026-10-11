<?php

use App\Support\Hosts\PlatformHosts;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

test('the health endpoint answers 200 when the database and cache respond', function () {
    $this->get(PlatformHosts::url(PlatformHosts::root(), 'up'))->assertOk();
});

test('the health endpoint answers 500 when the cache is down', function () {
    Cache::shouldReceive('store')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    $this->get(PlatformHosts::url(PlatformHosts::root(), 'up'))->assertStatus(500);
});

test('a growing queue backlog is logged as critical', function () {
    Log::spy();

    event(new QueueBusy('redis', 'default', 812));

    Log::shouldHaveReceived('critical')->with('Queue backlog over threshold', [
        'connection' => 'redis',
        'queue' => 'default',
        'size' => 812,
    ])->once();
});

test('the scheduler watches the queue, the audit export and the audit chain', function () {
    $this->artisan('schedule:list')
        // Arguments are quoted with " on Windows and ' on Linux.
        ->expectsOutputToContain('queue:monitor '.(PHP_OS_FAMILY === 'Windows' ? '"redis:default"' : "'redis:default'").' --max=500')
        ->expectsOutputToContain('platform:audit-export')
        ->expectsOutputToContain('platform:audit-verify')
        ->assertSuccessful();
});
