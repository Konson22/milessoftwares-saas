<?php

namespace Tests;

use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The business workIn() last signed in to; businessUrl() defaults to it.
     */
    public ?Organization $business = null;

    /**
     * Absolute URL on the auth host, where Fortify and account settings live.
     */
    protected function authUrl(string $path = '/'): string
    {
        return PlatformHosts::url(PlatformHosts::auth(), $path);
    }

    /**
     * Signs in as the member and makes their business the default host.
     */
    public function workIn(OrganizationMembership $membership): static
    {
        $this->actingAs($membership->user);
        $this->business = Organization::query()->findOrFail($membership->organization_id);

        return $this;
    }

    /**
     * Absolute URL on a business's own host ({slug}.<root>), read fresh so a
     * slug rename in the test is followed.
     */
    public function businessUrl(string $path = '/', ?Organization $organization = null): string
    {
        $organization ??= $this->business ?? throw new LogicException('Call workIn() first.');
        $slug = OrganizationSlug::query()
            ->where('organization_id', $organization->id)
            ->where('is_primary', true)
            ->value('slug');

        return PlatformHosts::url(PlatformHosts::workspace($slug), $path);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
