<?php

namespace App\Access;

/**
 * The signed-in member's access in the current organization, for this
 * request (scoped binding). Laravel's Gate consults it (AppServiceProvider),
 * so ->can('projects.view') and @can work with catalog permissions.
 */
final class CurrentAccess
{
    private ?Access $access = null;

    public function set(Access $access): void
    {
        $this->access = $access;
    }

    public function get(): ?Access
    {
        return $this->access;
    }

    public function clear(): void
    {
        $this->access = null;
    }
}
