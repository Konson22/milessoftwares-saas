<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithOrganizations;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    /** The permission catalog and system roles (DatabaseSeeder). */
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // The app stands alone: no test may reach another service.
        Http::preventStrayRequests();
    }
}
