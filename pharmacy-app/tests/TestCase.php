<?php

namespace Tests;

use App\Models\User;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Models\TillSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Miles\PlatformClient\Testing\InteractsWithPlatform;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithPlatform;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Opens a till for this person at the branch, as the counter needs
     * before anything is sold.
     */
    protected function openTill(Authenticatable $user, string $branchId, int $floatMinor = 0, string $slug = 'acme'): TillSession
    {
        assert($user instanceof User);

        return $this->asTenant($slug, fn () => app(Tills::class)->open($user, $branchId, $floatMinor));
    }
}
