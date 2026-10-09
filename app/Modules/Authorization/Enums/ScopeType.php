<?php

namespace App\Modules\Authorization\Enums;

/**
 * Where a role assignment applies. Each application's catalog declares which
 * scope types each role and permission supports.
 */
enum ScopeType: string
{
    case Organization = 'organization';
    case Branch = 'branch';
    case Department = 'department';
    case Resource = 'resource';
}
