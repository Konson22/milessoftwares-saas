<?php

namespace App\Modules\Memberships\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Removed = 'removed';
}
