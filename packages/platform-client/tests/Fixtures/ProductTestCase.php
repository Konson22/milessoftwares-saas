<?php

namespace Miles\PlatformClient\Tests\Fixtures;

use Miles\PlatformClient\Testing\InteractsWithPlatform;
use Miles\PlatformClient\Tests\TestCase;

/**
 * How a product's test case uses the testing helpers (and lets PHPStan
 * analyse them).
 */
abstract class ProductTestCase extends TestCase
{
    use InteractsWithPlatform;
}
