<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Miles\PlatformClient\Testing\InteractsWithPlatform;
use Tests\Support\SendsEvents;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithPlatform;
    use RefreshDatabase;
    use SendsEvents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
