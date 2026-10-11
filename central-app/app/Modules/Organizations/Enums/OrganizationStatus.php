<?php

namespace App\Modules\Organizations\Enums;

enum OrganizationStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * Whether the workspace host resolves at all. Pending (not fully
     * provisioned) and archived organizations behave as if they do not exist.
     */
    public function resolvesWorkspace(): bool
    {
        return $this === self::Active || $this === self::Suspended;
    }
}
