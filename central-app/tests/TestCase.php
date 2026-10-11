<?php

namespace Tests;

use App\Support\Hosts\PlatformHosts;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Absolute URL on the auth host, where Fortify and account settings live.
     */
    protected function authUrl(string $path = '/'): string
    {
        return PlatformHosts::url(PlatformHosts::auth(), $path);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
