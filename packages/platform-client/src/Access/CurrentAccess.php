<?php

namespace Miles\PlatformClient\Access;

use Miles\PlatformClient\Api\EffectiveAccess;

/**
 * Effective access of the signed-in member for this request, set by the
 * platform.access middleware. Laravel's Gate consults it, so
 * $user->can('pharmacy.dispense') and @can work with catalog permissions.
 */
final class CurrentAccess
{
    private ?EffectiveAccess $access = null;

    public function set(EffectiveAccess $access): void
    {
        $this->access = $access;
    }

    public function get(): ?EffectiveAccess
    {
        return $this->access;
    }
}
